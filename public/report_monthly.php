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
<?php render_header('รายงานประจำเดือน • Fleet'); ?>
<?php
require_once __DIR__.'/../inc/helpers.php';
require_once __DIR__.'/../inc/db.php';

$u = require_role(['ADMIN','APPROVER_L1','APPROVER_L2']);
$pdo = db();

/* ---------- รับพารามิเตอร์กรอง ---------- */
$month      = $_GET['month'] ?? date('Y-m');           // YYYY-MM
$vehicle_id = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0;

$start = $month.'-01';
$end   = date('Y-m-d', strtotime("$start +1 month"));

/* ---------- โหลดรายชื่อรถสำหรับเลือกกรอง ---------- */
$vehRows = $pdo->query("
  SELECT id, plate_no, brand_model
  FROM vehicles
  WHERE active=1
  ORDER BY plate_no ASC
")->fetchAll();

/* ---------- ดึงข้อมูลทริปตามช่วงเวลา (ถ้ามี vehicle_id จะกรองเพิ่ม) ---------- */
$params = [$start, $end];
$whereVehicle = '';
if ($vehicle_id) {
  $whereVehicle = " AND t.vehicle_id = ? ";
  $params[] = $vehicle_id;
}

$stmt = $pdo->prepare("
  SELECT t.*, b.user_id, v.plate_no, v.brand_model, u.name AS user_name
  FROM trips t
  JOIN bookings b ON b.id = t.booking_id
  JOIN vehicles v ON v.id = t.vehicle_id
  JOIN users u    ON u.id = b.user_id
  WHERE t.actual_start >= ? AND t.actual_start < ?
  $whereVehicle
  ORDER BY t.actual_start ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();
?>
  <style>
    .inputs-row { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; }
    .inputs-row > div { display:flex; flex-direction:column; }
    .btn[disabled] { opacity:.5; pointer-events:none; }
  </style>

  <div class="card">
    <h2>รายงานประจำเดือน</h2>
    <?= function_exists('form_brand_badge') ? form_brand_badge() : '' ?>
    <form method="get" id="filters" class="inputs-row">
      <label>รอบเดือน (YYYY-MM)</label>
      <input name="month" id="month" value="<?= h($month) ?>" pattern="\d{4}-\d{2}" required>

      <label>เลือกรถ</label>
      <select name="vehicle_id" id="vehicle_id" required>
        <option value="0" <?= $vehicle_id===0?'selected':''; ?>>— ทุกคัน —</option>
        <?php foreach($vehRows as $v): ?>
          <option value="<?= (int)$v['id'] ?>" <?= $vehicle_id===(int)$v['id'] ? 'selected' : '' ?>>
            <?= h($v['plate_no'].' • '.$v['brand_model']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <button type="submit">ดูรายงาน</button>

     
    </form>
  </div>
  <div class="card">
       <!-- ปุ่มออกรายงาน PDF: ต้องเลือกรถก่อน -->
      <a id="btn-pdf-monthly" class="btn" target="_blank"
         href="report_monthly_pdf.php?month=<?= h($month) ?>&vehicle_id=<?= (int)$vehicle_id ?>">
        บันทึกการใช้รถยนต์ (FM-อาคาร-02-02)
      </a>

      <a id="btn-pdf-fuelcard" class="btn" target="_blank"
         href="report_fuelcard_pdf.php?month=<?= h($month) ?>&vehicle_id=<?= (int)$vehicle_id ?>">
        ทะเบียนคุมการใช้บัตรเติมน้ำมัน (FM-อาคาร-02-04)
      </a>

      <a
        id="btn-inspect-pdf"
        class="btn"
        target="_blank"
        href="report_vehicle_inspection_monthly_pdf.php?month=<?= h($month) ?>&vehicle_id=<?= (int)$vehicle_id ?>"
      >
        รายการตรวจสภาพรถก่อนใช้งานรายวัน (FM-อาคาร-02-06)
      </a>

      <div class="small" style="margin-top:6px; color:#666; width:100%;">
        * ปุ่ม PDF ต้องเลือกรถก่อน (ระบบจะเปิดไฟล์ PDF ในแท็บใหม่)
      </div>
  </div>

  <div class="card">
    <table>
      <tr>
        <th>วันที่</th><th>ทะเบียน</th><th>ผู้ใช้</th>
        <th>ไมล์เริ่ม</th><th>ไมล์สิ้นสุด</th>
        <th>กม. (เลือกใช้)</th><th>กม. (GPS)</th>
        <th>วิธี</th><th>ค่าน้ำมัน</th>
        <th>ใบออกรถ</th>
      </tr>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?= h($r['actual_start']) ?></td>
          <td><?= h($r['plate_no']) ?></td>
          <td><?= h($r['user_name']) ?></td>
          <td><?= h($r['start_odometer']) ?></td>
          <td><?= h($r['end_odometer']) ?></td>
          <td><?= h($r['total_km']) ?></td>
          <td><?= h($r['gps_total_km']) ?></td>
          <td><?= h($r['distance_source']) ?></td>
          <td><?= h(money_thb($r['fuel_cost'])) ?> ฿</td>
          <td>
            <a class="btn" target="_blank" href="trip_pdf.php?trip_id=<?= (int)$r['id'] ?>">
              ใบขอใช้รถ
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if(empty($rows)): ?>
        <tr><td colspan="10" style="text-align:center; color:#666;">— ไม่มีข้อมูลในช่วงที่เลือก —</td></tr>
      <?php endif; ?>
    </table>
  </div>

<script>
(function(){
  const monthInput   = document.getElementById('month');
  const vehSelect    = document.getElementById('vehicle_id');
  const btnMonthly   = document.getElementById('btn-pdf-monthly');
  const btnFuelCard  = document.getElementById('btn-pdf-fuelcard');
  const btnInspect   = document.getElementById('btn-inspect-pdf');

  function syncLinks(){
    const m  = (monthInput.value || '').trim();
    const id = parseInt(vehSelect.value || '0', 10);

    // อัปเดตลิงก์ปลายทาง
    btnMonthly.href  = 'report_monthly_pdf.php?month=' + encodeURIComponent(m) + '&vehicle_id=' + id;
    btnFuelCard.href = 'report_fuelcard_pdf.php?month=' + encodeURIComponent(m) + '&vehicle_id=' + id;
    btnInspect.href  = 'report_vehicle_inspection_monthly_pdf.php?month=' + encodeURIComponent(m) + '&vehicle_id=' + id;

    // ต้องเลือกรถก่อน (id > 0) ถึงจะกด PDF ได้
    const enablePdf = id > 0;
    toggleBtn(btnMonthly,  enablePdf);
    toggleBtn(btnFuelCard, enablePdf);
    toggleBtn(btnInspect,  enablePdf);
  }

  function toggleBtn(el, enabled){
    if (!el) return;
    if (enabled) {
      el.style.pointerEvents = '';
      el.style.opacity = '';
      el.setAttribute('aria-disabled', 'false');
    } else {
      el.style.pointerEvents = 'none';
      el.style.opacity = '.5';
      el.setAttribute('aria-disabled', 'true');
    }
  }

  monthInput.addEventListener('input', syncLinks);
  vehSelect.addEventListener('change', syncLinks);
  syncLinks();
})();
</script>

<?php render_footer(); ?>
