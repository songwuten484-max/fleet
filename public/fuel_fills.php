<?php
// public/fuel_fills.php
// แสดงรายการเติมน้ำมันทั้งหมด (การ์ด)
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

require_once __DIR__.'/../inc/config.php';
require_once __DIR__.'/../inc/layout.php';
require_once __DIR__.'/../inc/helpers.php';
require_once __DIR__.'/../inc/db.php';

$u   = require_role(['ADMIN','APPROVER_L1','APPROVER_L2','DRIVER']);
$pdo = db();

/* ---------- ฟังก์ชันช่วย ---------- */
function public_base_url(): string {
    $base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    return $base === '' ? '/' : $base;
}

function slip_url($file) {
    if (!$file) return null;
    if (preg_match('~^(https?://|data:)~i', $file)) return $file;

    $base   = public_base_url();
    $pubDir = str_replace('\\','/', realpath(__DIR__));

    $candidates = [
        ltrim($file, '/'),
        'uploads/fuel_slips/' . ltrim($file, '/'),
        'uploads/fuel_slips/' . basename($file),
    ];

    foreach ($candidates as $rel) {
        $abs = $pubDir . '/' . $rel;
        if (is_file($abs)) return $base . '/' . $rel;
    }
    return $base . '/' . ltrim($file, '/');
}

/* ---------- รับค่ากรอง ---------- */
$month      = $_GET['month'] ?? date('Y-m');   // YYYY-MM
$vehicle_id = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0;

$start = $month . '-01';
$end   = date('Y-m-d', strtotime("$start +1 month"));

/* ---------- โหลดรายชื่อรถ ---------- */
$vehRows = $pdo->query("SELECT id, plate_no, brand_model FROM vehicles WHERE active=1 ORDER BY plate_no ASC")->fetchAll();

/* ---------- ดึงข้อมูล ---------- */
$params = [$start, $end];
$where  = '';
if ($vehicle_id) {
    $where = " AND fct.vehicle_id=? ";
    $params[] = $vehicle_id;
}

$stmt = $pdo->prepare("
  SELECT fct.*, v.plate_no, v.brand_model, u.name AS driver_name
  FROM fuel_card_transactions fct
  JOIN vehicles v ON v.id=fct.vehicle_id
  LEFT JOIN users u ON u.id=fct.driver_id
  WHERE fct.tx_date >= ? AND fct.tx_date < ?
  $where
  ORDER BY fct.tx_date DESC, fct.id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

render_header('รายการเติมน้ำมัน • Fleet');
?>

<div class="card">
  <div style="display:flex; justify-content:space-between; align-items:flex-end; gap:12px; flex-wrap:wrap;">
    <div>
      <h2>รายการเติมน้ำมัน</h2>
      <form method="get" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <label>รอบเดือน (YYYY-MM)</label>
        <input type="month" name="month" value="<?= h($month) ?>">
        <label>เลือกรถ</label>
        <select name="vehicle_id">
          <option value="0">— ทุกคัน —</option>
          <?php foreach($vehRows as $v): ?>
            <option value="<?= (int)$v['id'] ?>" <?= $vehicle_id===(int)$v['id'] ? 'selected' : '' ?>>
              <?= h($v['plate_no'].' • '.$v['brand_model']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div>
          <button type="submit">กรอง</button>
          <a class="btn" href="fuel_fill_new.php">+ เติมน้ำมัน</a>
        </div>
      </form>
    </div>
  </div>
</div>

<div style="display:flex; flex-wrap:wrap; gap:16px; margin-top:12px;">
  <?php if (empty($rows)): ?>
    <div style="color:#666;">— ไม่มีข้อมูล —</div>
  <?php else: ?>
    <?php foreach ($rows as $r): ?>
      <div style="border:1px solid #ccc; border-radius:8px; padding:10px; width:260px; background:#fafafa; position:relative;">
        <?php if (!empty($r['receipt_file'])): ?>
          <?php $full = slip_url($r['receipt_file']); ?>
          <div style="text-align:center; margin-bottom:6px;">
            <a href="<?= h($full) ?>" target="_blank" title="เปิดรูปขนาดเต็ม">
              <img src="<?= h($full) ?>" alt="slip" style="max-width:100%; max-height:160px; border-radius:4px;">
            </a>
          </div>
          <div style="text-align:center; margin-bottom:8px;">
            <a class="btn secondary" href="<?= h($full) ?>" target="_blank">ดูภาพเต็ม</a>
          </div>
        <?php endif; ?>

        <div><b>วันที่:</b> <?= h($r['tx_date']) ?></div>
        <div><b>รถ:</b> <?= h($r['plate_no']) ?> <span style="color:#777;">• <?= h($r['brand_model']) ?></span></div>
        <div><b>ลิตร:</b> <?= $r['liters']!==null ? h(number_format((float)$r['liters'],3)) : '-' ?></div>
        <div><b>ราคา/ลิตร:</b> <?= $r['price_per_liter']!==null ? h(number_format((float)$r['price_per_liter'],2)) : '-' ?></div>
        <div><b>ยอดเงิน:</b> <?= $r['amount']!==null ? h(number_format((float)$r['amount'],2)) : '-' ?> บาท</div>
        <div><b>เลขไมล์:</b> <?= $r['odometer']!==null ? h(number_format((int)$r['odometer'])) : '-' ?></div>
        <div><b>ใบเสร็จ:</b> <?= h($r['receipt_no'] ?? '-') ?></div>
        <div style="font-size:11pt; color:#555;"><b>โดย:</b> <?= h($r['driver_name'] ?? '-') ?></div>
        <?php if (!empty($r['notes'])): ?>
          <div style="font-size:11pt; color:#555;"><b>หมายเหตุ:</b> <?= h($r['notes']) ?></div>
        <?php endif; ?>

        <!-- 🔹 ปุ่มแก้ไข -->
        <div style="text-align:center; margin-top:10px;">
          <a href="fuel_fill_edit.php?id=<?= (int)$r['id'] ?>" class="btn" style="background:#0B5ED7; color:#fff;">✏️ แก้ไข</a>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
