<?php
// public/report_vehicle_inspection_monthly_pdf.php
// รายงานแบบบันทึกรายการตรวจสภาพรถก่อนใช้งานรายวัน (ทั้งเดือน) — FM-อาคาร-02-06
// พารามิเตอร์: ?month=YYYY-MM&vehicle_id=ID
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

$u = require_role(['ADMIN','APPROVER_L1','APPROVER_L2','DRIVER']);
$pdo = db();

/* -------- รับพารามิเตอร์ -------- */
$month = $_GET['month'] ?? date('Y-m');
$vehicle_id = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0;

if (!$vehicle_id) { http_response_code(400); echo "ต้องระบุ vehicle_id"; exit; }
if (!preg_match('/^\d{4}-\d{2}$/', $month)) { http_response_code(400); echo "รูปแบบ month ไม่ถูกต้อง (YYYY-MM)"; exit; }

$start = $month . '-01';
$end   = date('Y-m-d', strtotime("$start +1 month"));

/* -------- โหลดข้อมูลรถ -------- */
$veh = $pdo->prepare("SELECT id, plate_no, brand_model FROM vehicles WHERE id=? LIMIT 1");
$veh->execute([$vehicle_id]);
$vehicle = $veh->fetch();
if (!$vehicle) { http_response_code(404); echo "ไม่พบรถ"; exit; }

/* -------- ดึงข้อมูลตรวจของเดือนนี้ทั้งหมด (คันนี้) -------- */
$stmt = $pdo->prepare("
  SELECT vi.*, u.name AS driver_name
  FROM vehicle_inspections vi
  LEFT JOIN users u ON u.id = vi.driver_id
  WHERE vi.vehicle_id = ?
    AND vi.inspect_date >= ? AND vi.inspect_date < ?
  ORDER BY vi.inspect_date ASC, vi.id ASC
");
$stmt->execute([$vehicle_id, $start, $end]);
$inspections = $stmt->fetchAll();

/* -------- จัด index ตามวันที่ -------- */
$byDay = [];   // d (1..31) => array of inspection rows
foreach ($inspections as $row) {
  $d = (int)date('j', strtotime($row['inspect_date']));
  $byDay[$d] = $byDay[$d] ?? [];
  $byDay[$d][] = $row;
}

/* -------- helper ไทย -------- */
function th_month_year($ym) {
  if (!preg_match('/^\d{4}-\d{2}$/', $ym)) return $ym;
  [$y,$m] = explode('-', $ym);
  $thai = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
  return $thai[(int)$m - 1] . ' พ.ศ. ' . ((int)$y + 543);
}

/* -------- กำหนดรายการแถว 1–16 ตามแบบ -------- */
$rowsDef = [
  ['fuel_ok',         '1. น้ำมันเชื้อเพลิง'],
  ['engine_oil_ok',   '2. ระดับน้ำมันเครื่อง'],
  ['radiator_ok',     '3. ระดับน้ำในหม้อน้ำและท่อยาง'],
  ['battery_ok',      '4. ระดับน้ำกลั่น'],
  ['battery_term_ok', '5. ขั้วแบตเตอรี่ สายรัด'],
  ['belt_ok',         '6. สายพานพัดลม'],
  ['brake_ok',        '7. น้ำมันเบรกและการทำงานของเบรก'],
  ['steering_ok',     '8. พวงมาลัย'],
  ['lights_ok',       '9. ไฟทุกดวง'],
  ['horn_ok',         '10. แตร'],
  ['wiper_ok',        '11. ที่ปัดน้ำฝน'],
  ['tires_ok',        '12. ยาง 4 ล้อ'],
  ['spare_ok',        '13. ยางอะไหล่'],
  ['tools_ok',        '14. เครื่องมือเปลี่ยนยาง'],
  ['clean_ok',        '15. ความสะอาดทั่วไป'],
  ['other_ok',        '16. อื่น ๆ (ถ้ามี)'],
];

/* -------- เครื่องหมายในตาราง --------
   ✓ = มีการตรวจและผ่าน (field=1)
   ✗ = มีการตรวจและไม่ผ่าน (field=0)
   – = ไม่มีข้อมูลของวันนั้น (ไม่ได้ตรวจ)
--------------------------------------- */
function markCell(?array $rowsInDay, string $field): string {
  if (!$rowsInDay || !count($rowsInDay)) return '–';
  $hasRecord = false;
  foreach ($rowsInDay as $r) {
    if (array_key_exists($field, $r)) {
      $hasRecord = true;
      if ((int)$r[$field] !== 1) return '✗';
    }
  }
  return $hasRecord ? '✓' : '–';
}

/* -------- รวม remark ของแต่ละวัน -------- */
$remarksByDay = [];
foreach ($byDay as $d => $rowsInDay) {
  $txt = [];
  foreach ($rowsInDay as $r) {
    if (!empty($r['defects_text'])) $txt[] = trim($r['defects_text']);
    if (!empty($r['other_text']))   $txt[] = 'อื่นๆ: ' . trim($r['other_text']);
    if (!empty($r['notes']))        $txt[] = 'บันทึก: ' . trim($r['notes']);
  }
  $remarksByDay[$d] = implode(' | ', array_filter($txt));
}

/* -------- mPDF (A4 Landscape) -------- */
$defCfg  = (new \Mpdf\Config\ConfigVariables())->getDefaults();
$fontDir = $defCfg['fontDir'];
$defFont = (new \Mpdf\Config\FontVariables())->getDefaults();
$fontData= $defFont['fontdata'];

$mpdf = new \Mpdf\Mpdf([
  'mode'          => 'utf-8',
  'format'        => 'A4-L',
  'margin_top'    => 12,
  'margin_right'  => 10,
  'margin_bottom' => 10,
  'margin_left'   => 10,
  'fontDir'       => array_merge($fontDir, [__DIR__ . '/fonts', __DIR__ . '/../public/fonts']),
  'fontdata'      => $fontData + [
    // TH Sarabun สำหรับเนื้อความ
    'sarabun' => [
      'R'  => 'THSarabunNew.ttf',
      'B'  => 'THSarabunNew Bold.ttf',
      'I'  => 'THSarabunNew Italic.ttf',
      'BI' => 'THSarabunNew BoldItalic.ttf',
    ],
    // ใช้ DejaVu Sans (มีใน mPDF อยู่แล้วส่วนใหญ่) สำหรับสัญลักษณ์ ✓ ✗
    'dejavusanscondensed' => $fontData['dejavusanscondensed'] ?? [],
  ],
  'default_font'  => 'sarabun',
]);

/* -------- CSS -------- */
$css = <<<CSS
  body { font-family: 'sarabun'; font-size: 12pt; }
  .title { text-align:center; font-weight:bold; font-size:16pt; margin: 2px 0 8px; }
  .meta  { display:flex; justify-content:space-between; margin-bottom:6px; }
  .meta .line { display:inline-block; min-width:160px; border-bottom:1px dotted #000; padding:0 4px; }
  table.sheet { width:100%; border-collapse:collapse; }
  table.sheet th, table.sheet td { border:1px solid #000; padding:4px 4px; }
  table.sheet th { text-align:center; background:#f7f7f7; font-weight:bold; }
  td.center { text-align:center; }
  /* คอลัมน์เครื่องหมายใช้ฟอนต์ที่มี glyph ✓ ✗ */
  .check { font-family: 'dejavusanscondensed','sarabun'; font-size: 12pt; }
  .code { text-align:right; font-size:10pt; color:#666; margin-top:4px; }
CSS;

/* -------- สร้างส่วนหัว -------- */
$monthTH = th_month_year($month);
$plate   = h($vehicle['plate_no']) . ' • ' . h($vehicle['brand_model']);
$daysInMonth = (int)date('t', strtotime($start));

ob_start();
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<style><?= $css ?></style>
</head>
<body>

  <div class="title">แบบบันทึกรายการตรวจสภาพรถก่อนใช้งานรายวัน</div>
  
  <div style="text-align:right">
      รถเลขทะเบียน : <span class="line"><?= $plate ?></span>
      
    </div>
  <div style="text-align:right">
      พนักงานขับรถ : <span class="line">
        <?php
          $lastDriver = '';
          for ($d=$daysInMonth; $d>=1; $d--) {
            if (!empty($byDay[$d])) { $lastDriver = $byDay[$d][0]['driver_name'] ?? ''; break; }
          }
          echo h($lastDriver);
        ?>
      </span>
  </div>
  
  <div style="text-align:center">
      เริ่มใช้รถเมื่อเลขกิโลเมตรที่
      <span class="line">
        <?php
          $firstOdo = '';
          for ($d=1; $d<= $daysInMonth; $d++) {
            if (!empty($byDay[$d])) { $firstOdo = $byDay[$d][0]['start_odometer'] ?? ''; break; }
          }
          echo $firstOdo !== '' ? h(number_format((int)$firstOdo)) : '';
        ?>
      </span>
      &nbsp;&nbsp; เดือน <span class="line"><?= h($monthTH) ?></span>
    </div>



  <table class="sheet">
    <thead>
      <tr>
        <th style="width:20%;">รายการตรวจสอบ</th>
        <?php for($d=1;$d<=31;$d++): ?>
          <th style="width:2.5%;"><?= $d ?></th>
        <?php endfor; ?>
        <th style="width:17%;">รายงานสภาพบกพร่อง</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rowsDef as [$field,$label]): ?>
        <tr>
          <td><?= h($label) ?></td>
          <?php for($d=1;$d<=31;$d++): ?>
            <?php
              if ($d > $daysInMonth) {
                $mark = ''; // เกินจำนวนวันในเดือน
              } else {
                $mark = markCell($byDay[$d] ?? null, $field);
              }
            ?>
            <!-- สำคัญ: ไม่ใช้ h() ที่นี่ เพื่อไม่ให้ encode ✓/✗; บังคับฟอนต์ด้วย .check -->
            <td class="center check"><?= $mark ?></td>
          <?php endfor; ?>
          <td>
            <?php
              if ($field === 'fuel_ok') {
                $bag = [];
                for ($d=1;$d<= $daysInMonth;$d++) {
                  if (!empty($remarksByDay[$d])) $bag[] = $d . ') ' . $remarksByDay[$d];
                }
                echo h(implode('  |  ', $bag));
              }
            ?>
          </td>
        </tr>
      <?php endforeach; ?>

      <?php for($i=1;$i<=3;$i++): ?>
        <tr>
          <td><?= $i ?>..................................................</td>
          <?php for($d=1;$d<=31;$d++): ?>
            <td></td>
          <?php endfor; ?>
          <td></td>
        </tr>
      <?php endfor; ?>
    </tbody>
  </table>

  <div class="code">FM-อาคาร-02-06</div>

</body>
</html>
<?php
$html = ob_get_clean();

$mpdf->WriteHTML($html);
$mpdf->Output("vehicle_inspections_{$month}_veh{$vehicle_id}.pdf", \Mpdf\Output\Destination::INLINE);
