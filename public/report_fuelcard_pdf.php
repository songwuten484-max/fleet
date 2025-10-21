<?php
// public/report_fuelcard_pdf.php
// ทะเบียนคุมการใช้บัตรเติมน้ำมัน (FM-อาคาร-02-04) ด้วย mPDF + TH Sarabun
// พารามิเตอร์: ?month=YYYY-MM&vehicle_id=ID  (ต้องเลือกรถ)
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

/* เลือกบัตรหลักของรถ (ถ้ามี) เพื่อโชว์บนหัวรายงาน */
$cardStmt = $pdo->prepare("
  SELECT fc.*
  FROM fuel_cards fc
  WHERE fc.vehicle_id=? AND fc.active=1
  ORDER BY (CASE WHEN fc.is_primary=1 THEN 0 ELSE 1 END), fc.id ASC
  LIMIT 1
");
$cardStmt->execute([$vehicle_id]);
$fuelcard = $cardStmt->fetch();

/* ---------- ดึงรายการใช้บัตรของเดือนนี้ (กรองตามรถ) ---------- */
/*  mapping คอลัมน์ตามสคีมา:
    - ผู้ใช้บัตร = users.name โดย JOIN ผ่าน fct.driver_id
    - ใบเสร็จ = fct.receipt_no
    - หมายเหตุ = fct.notes
*/
$tx = $pdo->prepare("
  SELECT fct.*, u.name AS user_name
  FROM fuel_card_transactions fct
  LEFT JOIN users u ON u.id = fct.driver_id
  WHERE fct.vehicle_id = ?
    AND fct.tx_date >= ? AND fct.tx_date < ?
  ORDER BY fct.tx_date ASC, fct.id ASC
");
$tx->execute([$vehicle_id, $start, $end]);
$rows = $tx->fetchAll();

/* ---------- helper: วันที่ไทย ---------- */
function th_full_date($iso) {
  if (!$iso) return '';
  $ts = strtotime($iso);
  $months = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน',
             'กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
  $d = (int)date('j', $ts);
  $m = $months[(int)date('n', $ts)-1];
  $y = (int)date('Y', $ts) + 543;
  return "$d $m $y";
}
function th_month_year($ym) {
  if (!preg_match('/^\d{4}-\d{2}$/', $ym)) return $ym;
  [$y,$m] = explode('-', $ym);
  $thai = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน',
           'กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
  return $thai[((int)$m)-1].' พ.ศ. '.(((int)$y)+543);
}

/* ---------- รวมยอด ---------- */
$total_amount = 0.0;
$total_liters = 0.0;
foreach ($rows as $r) {
  if ($r['amount'] !== null) $total_amount += (float)$r['amount'];
  if ($r['liters'] !== null) $total_liters += (float)$r['liters'];
}

/* ---------- ตั้งค่า mPDF + TH Sarabun (แนวตั้ง) ---------- */
$defCfg  = (new \Mpdf\Config\ConfigVariables())->getDefaults();
$fontDir = $defCfg['fontDir'];
$defFont = (new \Mpdf\Config\FontVariables())->getDefaults();
$fontData= $defFont['fontdata'];

$mpdf = new \Mpdf\Mpdf([
  'mode'          => 'utf-8',
  'format'        => 'A4',
  'margin_top'    => 16,
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

/* ---------- CSS ---------- */
$css = <<<CSS
  body { font-family: 'sarabun'; font-size: 12pt; }
  .title { font-weight: bold; font-size: 16pt; text-align:center; margin: 2px 0 6px; }
  .sub1  { text-align:center; margin-bottom: 2px; }
  .sub2  { text-align:center; margin-bottom: 8px; }
  .meta  { margin-bottom: 8px; }
  .meta .line { display:inline-block; border-bottom:1px dotted #000; min-width:140px; padding:0 4px; }
  .meta .lbl  { margin-right: 8px; }
  table.tbl { width:100%; border-collapse: collapse; }
  table.tbl th, table.tbl td { border:1px solid #000; padding:6px 6px; vertical-align:top; }
  table.tbl th { text-align:center; background:#f3f3f3; }
  td.center { text-align:center; }
  td.right  { text-align:right; }
  .sum { font-weight:bold; }
  .foot { font-size:10pt; color:#555; margin-top:6px; text-align:right; }
  .row { display:flex; gap:10px; align-items:center; margin: 4px 0; flex-wrap:wrap; }
CSS;

/* ---------- สร้าง HTML ---------- */
$org  = defined('BRAND_NAME_TH') ? BRAND_NAME_TH : 'คณะบริหารธุรกิจ';
$uni  = 'มหาวิทยาลัยเทคโนโลยีพระจอมเกล้าพระนครเหนือ';
$mon  = th_month_year($month);

$plate_text = h($veh['plate_no']).' • '.h($veh['brand_model']);
$card_no    = $fuelcard ? h($fuelcard['card_number']) : '-';
$credit_txt = ($fuelcard && $fuelcard['monthly_credit_limit']!==null)
            ? number_format((float)$fuelcard['monthly_credit_limit'], 0)
            : '-';

ob_start();
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<style><?= $css ?></style>
</head>
<body>

 <div class="title">ทะเบียนคุมการใช้บัตรเติมน้ำมันรถราชการ</div>
  <div class="sub1"><?= h($org) ?> <?= h($uni) ?></div>
  <div class="sub2">    <div class="row">
      <span class="lbl">หมายเลขทะเบียน:</span>
      <span class="line"><?= $plate_text ?></span>
      <span class="lbl">บัตรน้ำมันหมายเลข:</span>
      <span class="line"><?= $card_no ?></span>
  </div>
  <div class="row">
      <div class="row">
      <span class="lbl">วงเงินสินเชื่อต่อเดือน:</span>
      <span class="line"><?= $credit_txt ?> บาท</span>
      <span class="lbl">รอบเดือน:</span>
      <span class="line"><?= h($mon) ?></span>
    </div>
  </div></div>

  <table class="tbl">
    <thead>
      <tr>
        <th style="width:7%;">ลำดับ</th>
        <th style="width:15%;">วัน/เดือน/ปี</th>
        <th style="width:22%;">ชื่อ-สกุลผู้ใช้บัตร</th>
        <th style="width:12%;">เลขกิโลเมตร</th>
        <th style="width:10%;">ปริมาณ<br>(ลิตร)</th>
        <th style="width:12%;">ราคา/ลิตร<br>(บาท)</th>
        <th style="width:12%;">จำนวนเงิน<br>(บาท)</th>
        <th style="width:15%;">เลขที่ใบบันทึกรายการขาย<br>(Sale slip)</th>
        <th style="width:8%;">ลายมือชื่อ</th>
        <th style="width:10%;">หมายเหตุ</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="10" class="center">— ไม่มีข้อมูล —</td></tr>
      <?php else: ?>
        <?php $i=1; foreach ($rows as $r): ?>
          <tr>
            <td class="center"><?= $i ?></td>
            <td class="center"><?= h(th_full_date($r['tx_date'])) ?></td>
            <td><?= h($r['user_name'] ?? '') ?></td>
            <td class="right"><?= $r['odometer']!==null ? h(number_format((int)$r['odometer'])) : '' ?></td>
            <td class="right"><?= $r['liters']!==null ? h(number_format((float)$r['liters'], 3)) : '' ?></td>
            <td class="right"><?= $r['price_per_liter']!==null ? h(number_format((float)$r['price_per_liter'], 2)) : '' ?></td>
            <td class="right"><?= $r['amount']!==null ? h(number_format((float)$r['amount'], 2)) : '' ?></td>
            <td><?= h($r['receipt_no'] ?? '') ?></td>
            <td></td>
            <td><?= h($r['notes'] ?? '') ?></td>
          </tr>
        <?php $i++; endforeach; ?>
        <tr>
          <td colspan="4" class="right sum">รวม</td>
          <td class="right sum"><?= h(number_format($total_liters, 3)) ?></td>
          <td></td>
          <td class="right sum"><?= h(number_format($total_amount, 2)) ?></td>
          <td colspan="3"></td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>

  <div class="foot">FM-อาคาร-02-04</div>
</body>
</html>
<?php
$html = ob_get_clean();

$mpdf->WriteHTML($html);
$mpdf->Output("fuelcard_register_{$month}_veh{$vehicle_id}.pdf", \Mpdf\Output\Destination::INLINE);







