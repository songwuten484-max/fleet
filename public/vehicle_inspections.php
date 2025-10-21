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

$u   = require_role(['ADMIN','APPROVER_L1','APPROVER_L2','DRIVER']);
$pdo = db();

$month      = $_GET['month'] ?? date('Y-m');
$vehicle_id = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0;

$start = $month.'-01';
$end   = date('Y-m-d', strtotime("$start +1 month"));

$vehRows = $pdo->query("SELECT id, plate_no, brand_model FROM vehicles WHERE active=1 ORDER BY plate_no ASC")->fetchAll();

$params = [$start, $end];
$where  = '';
if ($vehicle_id) { $where .= " AND vi.vehicle_id=? "; $params[] = $vehicle_id; }

$stmt = $pdo->prepare("
  SELECT vi.*, v.plate_no, v.brand_model, u.name AS driver_name
  FROM vehicle_inspections vi
  JOIN vehicles v ON v.id=vi.vehicle_id
  LEFT JOIN users u ON u.id=vi.driver_id
  WHERE vi.inspect_date >= ? AND vi.inspect_date < ?
  $where
  ORDER BY vi.inspect_date DESC, vi.id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

render_header('รายการตรวจสภาพรถ • Fleet');
?>
<div class="card">
  <h2>รายการตรวจสภาพรถ</h2>
  <form method="get" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
<label>รอบเดือน (YYYY-MM)</label>
      <input type="month" name="month" value="<?=h($month)?>">
<label>เลือกรถ</label>
      <select name="vehicle_id">
        <option value="0">— ทุกคัน —</option>
        <?php foreach($vehRows as $v): ?>
          <option value="<?=$v['id']?>" <?= $vehicle_id===$v['id']?'selected':'' ?>>
            <?=h($v['plate_no'].' • '.$v['brand_model'])?>
          </option>
        <?php endforeach; ?>
      </select>
    <div>
      <button type="submit">กรอง</button>
      <a class="btn" href="vehicle_inspection_new.php">+ บันทึกตรวจสภาพ</a>
      <?php if ($vehicle_id): ?>
               <a id="btn-inspect-pdf" class="btn"
   target="_blank"
   href="report_vehicle_inspection_monthly_pdf.php?month=<?=h($month)?>&vehicle_id=<?= (int)$vehicle_id ?>"
   style="<?= $vehicle_id ? '' : 'pointer-events:none; opacity:.5;' ?>">
  ออกรายงานตรวจสภาพ (PDF)
</a>
      <?php else: ?>

      <?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <table>
    <tr>
      <th>วันที่ตรวจ</th><th>ทะเบียน</th><th>ผู้ตรวจ</th><th>เลข กม.เริ่ม</th><th>ผ่านทั้งหมด?</th><th>บกพร่อง</th><th>PDF</th>
    </tr>
    <?php foreach($rows as $r):
      $pass_all = (
        $r['fuel_ok'] && $r['engine_oil_ok'] && $r['radiator_ok'] && $r['battery_ok'] &&
        $r['battery_term_ok'] && $r['belt_ok'] && $r['brake_ok'] && $r['steering_ok'] &&
        $r['lights_ok'] && $r['horn_ok'] && $r['wiper_ok'] && $r['tires_ok'] &&
        $r['spare_ok'] && $r['tools_ok'] && $r['clean_ok'] && $r['other_ok']
      );
    ?>
      <tr>
        <td><?=h($r['inspect_date'])?></td>
        <td><?=h($r['plate_no'])?></td>
        <td><?=h($r['driver_name'] ?? '')?></td>
        <td><?= $r['start_odometer']!==null ? h(number_format($r['start_odometer'])) : '-' ?></td>
        <td><?= $pass_all ? 'ผ่าน' : 'มีบางรายการไม่ผ่าน' ?></td>
        <td><?= h(mb_strimwidth((string)$r['defects_text'],0,80,'…','UTF-8')) ?></td>
        <td><a class="btn" target="_blank" href="vehicle_inspection_pdf.php?id=<?=$r['id']?>">ใบตรวจ (PDF)</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if(empty($rows)): ?>
      <tr><td colspan="7" style="text-align:center;color:#666;">— ไม่มีข้อมูล —</td></tr>
    <?php endif; ?>
  </table>
</div>
<?php render_footer(); ?>
