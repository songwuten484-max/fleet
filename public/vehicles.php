<?php
session_name('FLEETSESSID'); // ใช้ชื่อเดียวกันทุกไฟล์
session_set_cookie_params([
  'lifetime' => 0,
  'path'     => '/',
  'domain'   => 'roombooking.fba.kmutnb.ac.th', // โดเมนจริงของคุณ
  'secure'   => true,   // ใช้ HTTPS เท่านั้น
  'httponly' => true,
  'samesite' => 'Lax',  // เหมาะกับ OAuth redirect
]);


session_start();
if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}
//$u = $_SESSION['user'];

require_once __DIR__.'/../inc/config.php';
require_once __DIR__.'/../inc/layout.php';
?>
<?php render_header('รถยนต์ • Fleet'); ?>
<?php
require_once __DIR__.'/../inc/helpers.php';
require_once __DIR__.'/../inc/db.php';

$u = require_role(['ADMIN','APPROVER_L1','APPROVER_L2']);
$pdo = db();

/* ---------- Ensure schema: เพิ่มคอลัมน์ is_campus_only ถ้ายังไม่มี ---------- */
try {
    $pdo->exec("ALTER TABLE vehicles ADD COLUMN is_campus_only TINYINT(1) NOT NULL DEFAULT 0");
} catch (Throwable $e) {
    // ถ้ามีอยู่แล้ว ข้ามไป
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    // เตรียมค่าตัวเลข (เว้นว่าง = NULL)
    $fuel_rate = ($_POST['fuel_rate_per_km'] !== '') ? (float)$_POST['fuel_rate_per_km'] : null;
    $gps_id    = ($_POST['gps_device_id']     !== '') ? $_POST['gps_device_id']          : null;
    $curr_odo  = ($_POST['current_odometer']  !== '') ? (int)$_POST['current_odometer']  : null;

    // ★ ประเภทการใช้งานรถ (ภายใน=1, ภายนอก=0)
    $campus_only = ($_POST['use_type'] === 'internal') ? 1 : 0;

    // เพิ่มข้อมูล
    $stmt = $pdo->prepare("
        INSERT INTO vehicles (plate_no, brand_model, fuel_rate_per_km, gps_device_id, current_odometer, is_campus_only)
        VALUES (?,?,?,?,?,?)
    ");
    $stmt->execute([$_POST['plate_no'], $_POST['brand_model'], $fuel_rate, $gps_id, $curr_odo, $campus_only]);
}

// โหลดรายการรถ
$rows = $pdo->query("SELECT * FROM vehicles ORDER BY id ASC")->fetchAll();
?>
  <div class="card">
    <h2>เพิ่มรถ</h2>
    <form method="post" autocomplete="off">
      <?php echo form_brand_badge(); ?>

      <label>ทะเบียนรถ</label>
      <input name="plate_no" required>

      <label>ยี่ห้อ/รุ่น</label>
      <input name="brand_model" required>

      <label>ค่าน้ำมัน (บาท/กม.) — เว้นว่างเพื่อใช้ค่าเริ่มต้น</label>
      <input name="fuel_rate_per_km" type="number" step="0.01" min="0">

      <label>GPS Device ID (ถ้ามี)</label>
      <input name="gps_device_id">

      <label>เลขไมล์ปัจจุบัน (กม.)</label>
      <input name="current_odometer" type="number" min="0" placeholder="เช่น 45,120">

      <!-- ★ ประเภทการใช้งาน -->
      <label>ประเภทการใช้งานรถ</label>
      <select name="use_type" required>
        <option value="internal">ใช้ภายในมหาวิทยาลัย</option>
        <option value="external">ใช้ภายนอกมหาวิทยาลัย</option>
      </select>

      <button>บันทึก</button>
    </form>
  </div>

  <div class="card">
    <h2>รายการรถ</h2>
    <table>
      <tr>
        <th>ID</th>
        <th>ทะเบียน</th>
        <th>ยี่ห้อ/รุ่น</th>
        <th>ค่าน้ำมัน</th>
        <th>GPS</th>
        <th>เลขไมล์ปัจจุบัน</th>
        <th>ประเภทการใช้งาน</th>
        <th>แก้ไข</th>
      </tr>
      <?php foreach($rows as $r): ?>
      <tr>
        <td><?= h($r['id']) ?></td>
        <td><?= h($r['plate_no']) ?></td>
        <td><?= h($r['brand_model']) ?></td>
        <td><?= h($r['fuel_rate_per_km'] ?? (defined('DEFAULT_FUEL_RATE_PER_KM') ? DEFAULT_FUEL_RATE_PER_KM : 0)) ?> ฿/กม.</td>
        <td><?= h($r['gps_device_id']) ?></td>
        <td><?= $r['current_odometer'] ? number_format((int)$r['current_odometer']) : '-' ?></td>
        <td>
          <?php if (!empty($r['is_campus_only'])): ?>
            <span class="badge" style="background:#2563eb;color:#fff;padding:.2rem .6rem;border-radius:.5rem;">ภายในมหาวิทยาลัย</span>
          <?php else: ?>
            <span class="badge" style="background:#64748b;color:#fff;padding:.2rem .6rem;border-radius:.5rem;">ภายนอกมหาวิทยาลัย</span>
          <?php endif; ?>
        </td>
        <td><a class="btn secondary" href="vehicles_edit.php?id=<?= h($r['id']) ?>">แก้ไข</a></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

<?php render_footer(); ?>
