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
require_once __DIR__.'/../inc/helpers.php';
require_once __DIR__.'/../inc/db.php';

$u = require_role(['ADMIN','APPROVER_L1','APPROVER_L2']);
$pdo = db();

/* ---------- Ensure schema: เพิ่มคอลัมน์ is_campus_only ถ้ายังไม่มี ---------- */
try {
    $pdo->exec("ALTER TABLE vehicles ADD COLUMN is_campus_only TINYINT(1) NOT NULL DEFAULT 0");
} catch (Throwable $e) {
    // มีอยู่แล้ว / ล้มเหลวแบบไม่กระทบการทำงาน -> ข้าม
}

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id=?");
$stmt->execute([$id]);
$v = $stmt->fetch();
if(!$v){ http_response_code(404); echo "ไม่พบรถ"; exit; }

// หาเลขไมล์จากประวัติทริปล่าสุด (ใช้ max(end_odometer, start_odometer))
$tripMaxStmt = $pdo->prepare("
  SELECT MAX(COALESCE(end_odometer, start_odometer)) AS last_odo
  FROM trips
  WHERE vehicle_id = ?
");
$tripMaxStmt->execute([$id]);
$tripMax = $tripMaxStmt->fetch();
$lastTripOdo = $tripMax && $tripMax['last_odo'] !== null ? (int)$tripMax['last_odo'] : null;

// ค่าพื้นที่ห้ามต่ำกว่า: ค่าสูงสุดระหว่างเลขไมล์เดิมของรถ กับเลขไมล์จากทริปล่าสุด (ถ้ามี)
$floorOdo = max(
  (int)($v['current_odometer'] ?? 0),
  (int)($lastTripOdo ?? 0)
);

$error = null;

if($_SERVER['REQUEST_METHOD']==='POST'){
    $plate_no  = $_POST['plate_no'] ?? '';
    $brand     = $_POST['brand_model'] ?? '';
    $fuel_rate = ($_POST['fuel_rate_per_km'] !== '') ? (float)$_POST['fuel_rate_per_km'] : null;
    $gps_id    = ($_POST['gps_device_id']     !== '') ? $_POST['gps_device_id']          : null;
    $active    = ($_POST['active_status'] === '1') ? 1 : 0; // ✅ เปลี่ยนจาก checkbox เป็น select

    // ประเภทการใช้งาน: internal => 1 (ภายใน), external => 0 (ภายนอก)
    $use_type = $_POST['use_type'] ?? 'external';
    $is_campus_only = ($use_type === 'internal') ? 1 : 0;

    // current_odometer: เว้นว่าง = ไม่เปลี่ยน, ถ้ามีค่า ต้องไม่ต่ำกว่า $floorOdo
    $postedOdoRaw = $_POST['current_odometer'] ?? '';
    $postedOdo    = ($postedOdoRaw !== '' ? (int)$postedOdoRaw : null);

    if ($postedOdo !== null && $postedOdo < $floorOdo) {
        $error = 'เลขไมล์ปัจจุบันต้องไม่ต่ำกว่า '.number_format($floorOdo).' กม.';
    }

    if (!$error) {
        if ($postedOdo === null) {
            $upd = $pdo->prepare("
                UPDATE vehicles
                SET plate_no=?, brand_model=?, fuel_rate_per_km=?, gps_device_id=?, active=?, is_campus_only=?
                WHERE id=?
            ");
            $upd->execute([$plate_no, $brand, $fuel_rate, $gps_id, $active, $is_campus_only, $id]);
        } else {
            $upd = $pdo->prepare("
                UPDATE vehicles
                SET plate_no=?, brand_model=?, fuel_rate_per_km=?, gps_device_id=?, active=?, current_odometer=?, is_campus_only=?
                WHERE id=?
            ");
            $upd->execute([$plate_no, $brand, $fuel_rate, $gps_id, $active, $postedOdo, $is_campus_only, $id]);
        }
        redirect('vehicles.php');
    }

    // โหลดค่าล่าสุดใหม่ (กรณี error)
    $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id=?");
    $stmt->execute([$id]);
    $v = $stmt->fetch();
    $tripMaxStmt->execute([$id]);
    $tripMax = $tripMaxStmt->fetch();
    $lastTripOdo = $tripMax && $tripMax['last_odo'] !== null ? (int)$tripMax['last_odo'] : null;
    $floorOdo = max((int)($v['current_odometer'] ?? 0), (int)($lastTripOdo ?? 0));
}
?>
<?php render_header('แก้ไขรถ • Fleet'); ?>
  <?php if($error): ?>
    <div class="flash" style="background:#ffecec;border-color:#ffcccc;color:#a33;"><?= h($error) ?></div>
  <?php endif; ?>

  <div class="card">
    <h2>แก้ไขข้อมูลรถ #<?=h($v['id'])?></h2>
    <form method="post" autocomplete="off">
      <?php echo form_brand_badge(); ?>

      <label>ทะเบียนรถ</label>
      <input name="plate_no" value="<?=h($v['plate_no'])?>" required>

      <label>ยี่ห้อ/รุ่น</label>
      <input name="brand_model" value="<?=h($v['brand_model'])?>" required>

      <label>ค่าน้ำมัน (บาท/กม.)</label>
      <input name="fuel_rate_per_km" type="number" step="0.01" min="0" value="<?=h($v['fuel_rate_per_km'])?>">

      <label>GPS Device ID</label>
      <input name="gps_device_id" value="<?=h($v['gps_device_id'])?>">

      <label>ประเภทการใช้งานรถ</label>
      <select name="use_type" required>
        <option value="internal" <?= !empty($v['is_campus_only']) ? 'selected' : '' ?>>ใช้ภายในมหาวิทยาลัย</option>
        <option value="external" <?= empty($v['is_campus_only']) ? 'selected' : '' ?>>ใช้ภายนอกมหาวิทยาลัย</option>
      </select>

      <label>เลขไมล์ปัจจุบัน (กม.)</label>
      <input name="current_odometer"
             type="number"
             min="<?= (int)$floorOdo ?>"
             placeholder="อย่างน้อย <?= number_format($floorOdo) ?> กม."
             value="<?= h($v['current_odometer']) ?>">

      <div style="color:#666;margin:-4px 0 8px 0;">
        <?php if($lastTripOdo !== null): ?>
          <small>เลขไมล์จากทริปล่าสุด: <?= number_format($lastTripOdo) ?> กม.</small><br>
        <?php endif; ?>
        <?php if($v['current_odometer'] !== null): ?>
          <small>เลขไมล์ที่บันทึกปัจจุบัน: <?= number_format((int)$v['current_odometer']) ?> กม.</small>
        <?php endif; ?>
      </div>

      <!-- ✅ เปลี่ยนจาก checkbox เป็น select -->
      <label>สถานะการใช้งาน</label>
      <select name="active_status" required>
        <option value="1" <?= $v['active'] ? 'selected' : '' ?>>ใช้งานอยู่</option>
        <option value="0" <?= !$v['active'] ? 'selected' : '' ?>>หยุดใช้งาน</option>
      </select>

      <button>บันทึกการแก้ไข</button>
      <a class="btn secondary" href="vehicles.php">ยกเลิก</a>
    </form>
  </div>

<?php render_footer(); ?>
