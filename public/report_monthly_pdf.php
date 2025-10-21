<?php
// public/report_monthly_pdf.php
// รายงานประจำเดือนรูปแบบ FM-อาคาร-02-02 (บันทึกการใช้รถยนต์) ด้วย mPDF + TH Sarabun
// คอลัมน์:
// 1) ลำดับ
// 2) ออกเดินทาง วัน-เวลา
// 3) ผู้ใช้รถ
// 4) สถานที่ไป
// 5) เลข กม. เมื่อรถออก
// 6) กลับถึงสำนักงาน วัน-เวลา
// 7) เลข กม. เมื่อรถกลับ
// 8) รวมระยะ (กม.)
// 9) พนักงานขับรถ
// 10) หมายเหตุ
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

require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../vendor/autoload.php';

$u = require_role(['ADMIN','APPROVER_L1','APPROVER_L2']);
$pdo = db();

/* ---------- รับพารามิเตอร์ ---------- */
$month = $_GET['month'] ?? date('Y-m');
$vehicle_id = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0;

if (!$vehicle_id) { http_response_code(400); echo "ต้องระบุ vehicle_id"; exit; }
if (!preg_match('/^\d{4}-\d{2}$/', $month)) { http_response_code(400); echo "รูปแบบ month ไม่ถูกต้อง (YYYY-MM)"; exit; }

$start = $month.'-01';
$end   = date('Y-m-d', strtotime("$start +1 month"));

/* ---------- โหลดข้อมูลรถ ---------- */
$vehStmt = $pdo->prepare("SELECT id, plate_no, brand_model FROM vehicles WHERE id=? LIMIT 1");
$vehStmt->execute([$vehicle_id]);
$veh = $vehStmt->fetch();
if (!$veh) { http_response_code(404); echo "ไม่พบรถ"; exit; }

/* ---------- ดึงรายการทริปของเดือน/รถ ---------- */
$sql = "
  SELECT t.*,
         b.user_id, b.destination,
         v.plate_no, v.brand_model,
         u.name AS user_name,
         d.name AS driver_name
  FROM trips t
  JOIN bookings b ON b.id=t.booking_id
  JOIN vehicles v ON v.id=t.vehicle_id
  JOIN users u ON u.id=b.user_id
  LEFT JOIN users d ON d.id=t.driver_id
  WHERE t.actual_start >= ? AND t.actual_start < ?
    AND t.vehicle_id = ?
  ORDER BY t.actual_start ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$start, $end, $vehicle_id]);
$rows = $stmt->fetchAll();

/* ---------- helper: วันที่ไทย ---------- */
function th_full_date($iso) {
  if (!$iso) return '';
  $ts = strtotime($iso);
  $months = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
  $d = (int)date('j', $ts);
  $m = $months[(int)date('n', $ts)-1];
  $y = (int)date('Y', $ts) + 543;
  return "$d $m $y";
}
function th_month_year($ym) {
  if (!preg_match('/^\d{4}-\d{2}$/', $ym)) return $ym;
  [$y,$m] = explode('-', $ym);
  $thai = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
  $mm = (int)$m;
  $yy = (int)$y + 543;
  return $thai[$mm-1]." พ.ศ. ".$yy;
}
function hhmm($iso) { return $iso ? date('H:i', strtotime($iso)) : ''; }

/* ---------- รวมยอด ---------- */
$total_km_sum = 0.0;
foreach ($rows as $r) {
  if ($r['total_km'] !== null) $total_km_sum += (float)$r['total_km'];
}

/* ---------- ตั้งค่า mPDF + TH Sarabun (แนวนอน) ---------- */
$defCfg  = (new \Mpdf\Config\ConfigVariables())->getDefaults();
$fontDir = $defCfg['fontDir'];
$defFont = (new \Mpdf\Config\FontVariables())->getDefaults();
$fontData= $defFont['fontdata'];

$mpdf = new \Mpdf\Mpdf([
  'mode'          => 'utf-8',
  'format'        => 'A4-L',   // แนวนอน
  'margin_top'    => 12,
  'margin_right'  => 12,
  'margin_bottom' => 14,
  'margin_left'   => 12,
  'fontDir'       => array_merge($fontDir, [__DIR__ . '/fonts', __DIR__ . '/../public/fonts']),
  'fontdata'      => $fontData + [
    'sarabun' => [
      'R'  => 'THSarabunNew.ttf',
      'B'  => 'THSarabunNew Bold.ttf',
      'I'  => 'THSarabunNew Italic.ttf',
      'BI' => 'THSarabunNew BoldItalic.ttf',
    ],
  ],
  'default_font'  => 'sarabun',
]);

/* ---------- CSS / Header ---------- */
$css = <<<CSS
  body { font-family: 'sarabun'; font-size: 12pt; }
  .header { text-align:center; line-height:1.2; margin-bottom: 6px; }
  .title { font-size: 16pt; font-weight: bold; margin-top: 4px; }
  .sub   { font-size: 12pt; }
  .mt6{ margin-top:6px; }

  table.tbl { width:100%; border-collapse:collapse; margin-top:8px; }
  table.tbl th, table.tbl td { border:1px solid #000; padding:6px 6px; }
  table.tbl th { text-align:center; background:#f3f3f3; }
  td.center { text-align:center; }
  td.right  { text-align:right; }
  .sum-bold { font-weight:bold; }
  .code { text-align:right; font-size:10pt; color:#555; margin-top: 6px; }

  /* ทำหัว/ท้ายซ้ำอัตโนมัติเมื่อขึ้นหน้าใหม่ */
  thead { display: table-header-group; }
  tfoot { display: table-footer-group; }
  tr { page-break-inside: avoid; }
CSS;

$org      = defined('BRAND_NAME_TH') ? BRAND_NAME_TH : 'คณะบริหารธุรกิจ';
$uni      = 'มหาวิทยาลัยเทคโนโลยีพระจอมเกล้าพระนครเหนือ';
$mon_th   = th_month_year($month);
$plateTxt = h($veh['plate_no']).'  '.h($veh['brand_model']);

ob_start();
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<style><?= $css ?></style>
</head>
<body>

  <div class="header">
    <div class="title">บันทึกการใช้รถยนต์ หมายเลขทะเบียน&nbsp;&nbsp;<?= $plateTxt ?></div>
    <div class="sub"><?= h($org) ?> <?= h($uni) ?></div>
    <div class="sub">เดือน&nbsp;&nbsp;<?= h($mon_th) ?></div>
  </div>

  <table class="tbl">
    <thead>
      <tr>
        <th style="width:5%;">ลำดับ</th>
        <th style="width:14%;">ออกเดินทาง<br>วัน-เวลา</th>
        <th style="width:14%;">ผู้ใช้รถ</th>
        <th style="width:16%;">สถานที่ไป</th>
        <th style="width:9%;">เลข กม.<br>เมื่อรถออก</th>
        <th style="width:14%;">กลับถึงสำนักงาน<br>วัน-เวลา</th>
        <th style="width:9%;">เลข กม.<br>เมื่อรถกลับ</th>
        <th style="width:8%;">รวมระยะ<br>(กม.)</th>
        <th style="width:9%;">พนักงานขับรถ</th>
        <th style="width:6%;">หมายเหตุ</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="10" class="center">ไม่มีข้อมูล</td></tr>
      <?php else: ?>
        <?php $i=1; foreach ($rows as $r): ?>
          <tr>
            <td class="center"><?= $i ?></td>
            <td class="center">
              <?= h(th_full_date($r['actual_start'])) ?><br>
              <?= h(hhmm($r['actual_start'])) ?> น.
            </td>
            <td><?= h($r['user_name']) ?></td>
            <td><?= h($r['destination'] ?? '') ?></td>
            <td class="right"><?= $r['start_odometer']!==null ? h(number_format((int)$r['start_odometer'])) : '' ?></td>
            <td class="center">
              <?= h(th_full_date($r['actual_end'])) ?><br>
              <?= h(hhmm($r['actual_end'])) ?> น.
            </td>
            <td class="right"><?= $r['end_odometer']!==null ? h(number_format((int)$r['end_odometer'])) : '' ?></td>
            <td class="right"><?= $r['total_km']!==null ? h(number_format((float)$r['total_km'], 2)) : '' ?></td>
            <td class="center"><?= h($r['driver_name'] ?? '') ?></td>
            <td></td>
          </tr>
        <?php $i++; endforeach; ?>
        <tr>
          <td colspan="7" class="right sum-bold">รวมระยะทางทั้งสิ้น</td>
          <td class="right sum-bold"><?= h(number_format($total_km_sum, 2)) ?></td>
          <td colspan="2"></td>
        </tr>
      <?php endif; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="10" style="text-align:right; font-size:10pt; padding:4px 8px;">
          หน้า {PAGENO} / {nbpg}
        </td>
      </tr>
    </tfoot>
  </table>

  <div style="height:14px;"></div>

  <div style="text-align:right">
    <div>ผู้บันทึก .......................................................</div>
    <div>ผู้ควบคุม .......................................................</div>
  </div>

  <div class="code">FM-อาคาร-02-02</div>
</body>
</html>
<?php
$html = ob_get_clean();

$mpdf->WriteHTML($html);
$mpdf->Output("fleet_report_{$month}_veh{$vehicle_id}.pdf", \Mpdf\Output\Destination::INLINE);
