<?php
// พิมพ์ใบตรวจสภาพรถ (ใบเดี่ยวหรือรายเดือน) ตาม FM-อาคาร-02-06
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

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$month = $_GET['month'] ?? null;
$vehicle_id = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0;

$items = [
  'fuel_ok'        => '1. น้ำมันเชื้อเพลิง',
  'engine_oil_ok'  => '2. ระดับน้ำมันเครื่อง',
  'radiator_ok'    => '3. ระดับน้ำในหม้อน้ำและท่อยาง',
  'battery_ok'     => '4. ระดับน้ำกลั่น',
  'battery_term_ok'=> '5. ขั้วแบตเตอรี่ สายรัด',
  'belt_ok'        => '6. สายพานพัดลม',
  'brake_ok'       => '7. น้ำมันเบรกและการทำงานของเบรก',
  'steering_ok'    => '8. พวงมาลัย',
  'lights_ok'      => '9. ไฟทุกดวง',
  'horn_ok'        => '10. แตร',
  'wiper_ok'       => '11. ที่ปัดน้ำฝน',
  'tires_ok'       => '12. ยาง 4 ล้อ',
  'spare_ok'       => '13. ยางอะไหล่',
  'tools_ok'       => '14. เครื่องมือเปลี่ยนยาง',
  'clean_ok'       => '15. ความสะอาดทั่วไป',
  'other_ok'       => '16. อื่น ๆ (ถ้ามี)',
];

function th_date($iso){
  if(!$iso) return '';
  $t=strtotime($iso);
  $m=['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
  return date('j', $t).' '.$m[(int)date('n',$t)-1].' '.(date('Y',$t)+543);
}

$rows=[];
$header='';

if ($id) {
  $stmt=$pdo->prepare("
    SELECT vi.*, v.plate_no, v.brand_model, u.name AS driver_name
    FROM vehicle_inspections vi
    JOIN vehicles v ON v.id=vi.vehicle_id
    LEFT JOIN users u ON u.id=vi.driver_id
    WHERE vi.id=? LIMIT 1
  ");
  $stmt->execute([$id]);
  $one=$stmt->fetch();
  if(!$one){ http_response_code(404); echo "ไม่พบรายการ"; exit; }
  $rows = [$one];
  $header = "แบบรายการตรวจสภาพรถ (ใบเดี่ยว)";
} else {
  if(!$month || !$vehicle_id || !preg_match('/^\d{4}-\d{2}$/',$month)){
    http_response_code(400); echo "ต้องระบุ month=YYYY-MM และ vehicle_id"; exit;
  }
  $start=$month.'-01'; $end=date('Y-m-d', strtotime("$start +1 month"));
  $stmt=$pdo->prepare("
    SELECT vi.*, v.plate_no, v.brand_model, u.name AS driver_name
    FROM vehicle_inspections vi
    JOIN vehicles v ON v.id=vi.vehicle_id
    LEFT JOIN users u ON u.id=vi.driver_id
    WHERE vi.inspect_date >= ? AND vi.inspect_date < ? AND vi.vehicle_id=?
    ORDER BY vi.inspect_date ASC, vi.id ASC
  ");
  $stmt->execute([$start,$end,$vehicle_id]);
  $rows=$stmt->fetchAll();
  if(!$rows){ echo "ไม่มีข้อมูล"; exit; }
  $header = "แบบรายการตรวจสภาพรถ (รายเดือน)";
}

$defCfg=(new \Mpdf\Config\ConfigVariables())->getDefaults();
$fontDir=$defCfg['fontDir'];
$defFont=(new \Mpdf\Config\FontVariables())->getDefaults();
$fontData=$defFont['fontdata'];

$mpdf = new \Mpdf\Mpdf([
  'mode'=>'utf-8','format'=>'A4',
  'margin_top'=>16,'margin_right'=>12,'margin_bottom'=>14,'margin_left'=>12,
  'fontDir'=>array_merge($fontDir, [__DIR__ . '/fonts', __DIR__ . '/../public/fonts']),
  'fontdata'=>$fontData + [
    'sarabun'=>[
      'R'=>'THSarabunNew.ttf','B'=>'THSarabunNew Bold.ttf',
      'I'=>'THSarabunNew Italic.ttf','BI'=>'THSarabunNew BoldItalic.ttf',
    ],
  ],
  'default_font'=>'sarabun',
]);

$css = <<<CSS
body{font-family:'sarabun'; font-size:12pt;}
h2{ margin:0 0 6px; text-align:center;}
.meta{ text-align:center; margin-bottom:6px;}
table.tbl{ width:100%; border-collapse:collapse; margin-top:6px;}
.tbl th,.tbl td{ border:1px solid #000; padding:6px 6px; vertical-align:top;}
.tbl th{ background:#f3f3f3; text-align:center;}
.small{ font-size:10pt; color:#555; text-align:right; margin-top:6px;}
.check{ font-weight:bold; }
.pass{ color:#080; } .fail{ color:#c00; }
CSS;

ob_start();
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><style><?= $css ?></style></head><body>
<h2><?=h($header)?> (FM-อาคาร-02-06)</h2>
<?php foreach($rows as $r): ?>
  <div class="meta">
    รถเลขทะเบียน: <?=h($r['plate_no'].' • '.$r['brand_model'])?> |
    ผู้ขับ/ผู้ตรวจ: <?=h($r['driver_name'] ?? '-')?> |
    วันที่: <?=h(th_date($r['inspect_date']))?> |
    เริ่มใช้รถเมื่อเลขกิโลเมตรที่: <?= $r['start_odometer']!==null ? h(number_format($r['start_odometer'])) : '-' ?>
  </div>
  <table class="tbl">
    <thead><tr><th style="width:70%;">รายการตรวจสอบ</th><th style="width:30%;">ผล</th></tr></thead>
    <tbody>
      <?php foreach($items as $k=>$label):
        $ok = (int)$r[$k]===1;
      ?>
      <tr>
        <td><?=h($label)?></td>
        <td class="check"><?= $ok ? '<span class="pass">ผ่าน</span>' : '<span class="fail">ไม่ผ่าน</span>' ?></td>
      </tr>
      <?php endforeach; ?>
      <tr>
        <td>16. อื่น ๆ (รายละเอียด)</td>
        <td><?= h($r['other_text'] ?? '') ?></td>
      </tr>
      <tr>
        <td>สภาพบกพร่อง</td>
        <td><?= h($r['defects_text'] ?? '') ?></td>
      </tr>
      <tr>
        <td>บันทึกเพิ่มเติม</td>
        <td><?= h($r['notes'] ?? '') ?></td>
      </tr>
    </tbody>
  </table>
  <div style="height:10px;"></div>
<?php endforeach; ?>
<div class="small">พิมพ์เมื่อ <?=h(date('Y-m-d H:i:s'))?></div>
</body></html>
<?php
$html = ob_get_clean();
$mpdf->WriteHTML($html);
$fn = $id ? "vehicle_inspection_$id.pdf" : "vehicle_inspections_$month_veh$vehicle_id.pdf";
$mpdf->Output($fn, \Mpdf\Output\Destination::INLINE);
