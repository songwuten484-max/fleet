<?php
// trip_pdf.php — ใบอนุญาตใช้รถของคณะ (PDF) — ใช้ mPDF + ฟอนต์ TH Sarabun
// โครงสร้าง PDF เดิมเหมือนเดิม เปลี่ยนเฉพาะการอ้างรูป: ใช้ path ตรง (file://) และเพิ่มลายเซ็นผู้ขออนุญาต/พนักงานขับรถ

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

$pdo = db();
$u = require_role(['ADMIN', 'APPROVER_L1', 'APPROVER_L2', 'DRIVER']);

$trip_id = (int) ($_GET['trip_id'] ?? 0);
if (!$trip_id) { http_response_code(400); echo "missing trip_id"; exit; }

/* ---------- โหลดข้อมูลทริป ---------- */
$stmt = $pdo->prepare("
  SELECT
    t.*,
    b.user_id, b.purpose, b.destination, b.created_at,
    v.plate_no, v.brand_model,
    u.name AS user_name,           -- ผู้ขออนุญาต (owner ของ booking)
    d.name AS driver_name          -- คนขับ (จาก trips.driver_id)
  FROM trips t
  JOIN bookings b ON b.id = t.booking_id
  JOIN vehicles v ON v.id = t.vehicle_id
  JOIN users    u ON u.id = b.user_id
  LEFT JOIN users d ON d.id = t.driver_id
  WHERE t.id = ?
  LIMIT 1
");
$stmt->execute([$trip_id]);
$T = $stmt->fetch();
if (!$T) { http_response_code(404); echo "ไม่พบทริป"; exit; }

/* ---------- Helpers (คงเดิม) ---------- */
function thai_date_dmy($ts){
  if(!$ts) return '';
  $m = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
  return (int)date('j',$ts) . ' เดือน ' . $m[(int)date('n',$ts)-1] . ' พ.ศ. ' . ((int)date('Y',$ts)+543);
}
function thai_date_full($iso){ return $iso ? thai_date_dmy(strtotime($iso)) : ''; }
function hhmm($iso){ return $iso ? date('H.i', strtotime($iso)) : ''; }

/* ---------- เตรียมข้อมูลลงแบบฟอร์ม ---------- */
$today_th   = thai_date_full($T['created_at']);   // ใช้ created_at ของ booking
$start_th   = thai_date_full($T['actual_start']);
$end_th     = thai_date_full($T['actual_end']);
$start_time = hhmm($T['actual_start']);
$end_time   = hhmm($T['actual_end']);

$purpose    = $T['purpose'] ?? '';
$dest       = $T['destination'] ?? '';
$user_name  = $T['user_name'] ?? '';
$plate      = $T['plate_no'];
$brand_model= $T['brand_model'];

$odo_start  = $T['start_odometer'] !== null ? number_format((int)$T['start_odometer']) : '';
$odo_end    = $T['end_odometer']   !== null ? number_format((int)$T['end_odometer'])   : '';
$total_km   = $T['total_km']       !== null ? number_format((float)$T['total_km'], 0)  : '';

$fixed_approver_name = 'นางนัทธ์หทัย  รัตนบุรี';
$driver_name = $T['driver_name'] ?? '';

/* ---------- รูป: ใช้ "path ตรง" (file:// + absolute) ---------- */
function resolve_img_src($pathOrFile){
  if(!$pathOrFile) return null;

  $norm = ltrim($pathOrFile, "/\\");
  $candidates = [
    $pathOrFile,
    __DIR__ . '/' . $norm,                                // public/<path>
    dirname(__DIR__) . '/' . $norm,                       // root/<path>
    __DIR__ . '/uploads/signatures/' . basename($norm),   // public/uploads/signatures/<file>
    dirname(__DIR__) . '/public/uploads/signatures/' . basename($norm), // root/public/uploads/signatures/<file>
  ];

  foreach($candidates as $p){
    if(is_file($p)){
      $real = realpath($p);
      if($real){
        return 'file:///' . str_replace('\\','/',$real);
      }
    }
  }
  if (preg_match('~^https?://~i', $pathOrFile)) return $pathOrFile;

  error_log('[trip_pdf] signature not found for: '.$pathOrFile);
  return null;
}

/* ---------- เตรียมลายเซ็น: ตั้งค่า default + ดึงจาก users.signature_file ---------- */
$default_requester = '';  // ไม่มีให้ว่าง
$default_approver  = 'uploads/signatures/approver.png';
$default_driver    = '';  // ไม่มีให้ว่าง

// ดึง signature_file ของผู้ขออนุญาตและพนักงานขับรถ (ถ้ามี)
$req_sig_db = null; $drv_sig_db = null;
$ids = [];
if (!empty($T['user_id']))   $ids[] = (int)$T['user_id'];
if (!empty($T['driver_id'])) $ids[] = (int)$T['driver_id'];
$map = [];

if ($ids) {
  $in = implode(',', array_fill(0, count($ids), '?'));
  $q = $pdo->prepare("SELECT id, signature_file FROM users WHERE id IN ($in)");
  $q->execute($ids);
  foreach ($q->fetchAll() as $row) {
    $map[(int)$row['id']] = $row['signature_file'] ?? null;
  }
  if (!empty($T['user_id'])   && isset($map[(int)$T['user_id']]))   $req_sig_db = $map[(int)$T['user_id']];
  if (!empty($T['driver_id']) && isset($map[(int)$T['driver_id']])) $drv_sig_db = $map[(int)$T['driver_id']];
}

// สร้าง src (แปลงเป็น file://)
$sig_requester_src = resolve_img_src($req_sig_db ?: $default_requester);
$sig_approver_src  = resolve_img_src($default_approver);
$sig_driver_src    = resolve_img_src($drv_sig_db ?: $default_driver);

// tag รูป
$sig_requester_img = $sig_requester_src ? '<img class="sigimg" src="'.h($sig_requester_src).'">' : '';
$sig_approver_img  = $sig_approver_src  ? '<img class="sigimg" src="'.h($sig_approver_src).'">'   : '';
$sig_driver_img    = $sig_driver_src    ? '<img class="sigimg" src="'.h($sig_driver_src).'">'     : '';

/* ---------- ตั้งค่า mPDF + ฟอนต์ TH Sarabun ---------- */
$defCfg  = (new \Mpdf\Config\ConfigVariables())->getDefaults();
$fontDir = $defCfg['fontDir'];
$defFont = (new \Mpdf\Config\FontVariables())->getDefaults();
$fontData= $defFont['fontdata'];

$mpdf = new \Mpdf\Mpdf([
  'mode'          => 'utf-8',
  'format'        => 'A4',
  'margin_top'    => 20,
  'margin_right'  => 25,
  'margin_bottom' => 10,
  'margin_left'   => 25,
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

/* ---------- HTML (โครงสร้างเดิมทั้งหมด) ---------- */
$css = <<<CSS
  body { font-family: 'sarabun'; font-size: 15pt; }
  .center { text-align:center; }
  .line { border-bottom: 1px dotted #000; display:inline-block; min-width: 120px; padding: 0 4px; }
  .long  { min-width: 240px; }
  .box   { border:1px solid #000; padding:10px; }
  .row   { display:flex; justify-content:space-between; gap:14px; }
  .col   { flex: 1; }
  .small { font-size: 11pt; color:#333; }
  .mt8 { margin-top:8px; } .mt12{margin-top:12px;} .mt16{margin-top:16px;} .mt24{margin-top:24px;}
  table.meta { width:100%; border-collapse:collapse; }
  table.meta td { padding:6px 0; vertical-align:top; }
p {
  font-size: 15pt;
  text-align: justify;
  text-justify: inter-word;
  text-indent: 0.5em;
  line-height: 1.5;
  margin-top: 0px;     /* ลดระยะห่างด้านบน */
  margin-bottom: 0px;  /* ลดระยะห่างด้านล่าง */
}
  .sigbox { height: 70px; border-bottom: 1px dashed #777; position: relative; margin-bottom: 6px; }
  .sigimg { position:absolute; left:0; right:0; top:0; bottom:0; margin:auto; transform: translateY(20px); height:40px; object-fit:contain; }
  .sigcap { font-size: 12pt; text-align:center; }
CSS;

$html = <<<HTML
<html>
<head><meta charset="utf-8"><style>{$css}</style></head>
<body>

  <div class="center">
    <div style="font-weight:bold;">ใบอนุญาตใช้รถของคณะ</div>
  </div>

  <div style="text-align:right">คณะบริหารธุรกิจ</div>
  <div style="text-align:right">มหาวิทยาลัยเทคโนโลยีพระจอมเกล้าพระนครเหนือ</div>
  <div style="text-align:right">วันที่ {$today_th} </div></div>

  <div class="mt16">เรียน คณบดีคณะบริหารธุรกิจ</div>

  <p>
    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;ข้าพเจ้า $user_name ขออนุญาตใช้รถ เพื่อไปปฏิบัติราชการเรื่อง $purpose สถานที่ไปปฏิบัติราชการ {$dest}
     ในวันที่ {$start_th}
    เวลาออก {$start_time} น.
    ถึงวันที่ {$end_th}
    เวลากลับ {$end_time} น.
  </p>

  <div style="text-align:right">
    {$sig_requester_img}&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
    <div>ลงชื่อ .......................................................... ผู้ขออนุญาต</div>
    <div>({$user_name})&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</div>
    <div>วันที่ {$today_th}&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</div>
  </div>

  <div style="text-align:right">
    {$sig_approver_img}&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
    <div>ลงชื่อ .................................................. หัวหน้าสำนักงาน</div>
    <div>( {$fixed_approver_name} )&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</div>
    <div>วันที่ {$today_th}&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</div>
  </div>

  <div style="text-align:right">
    {$sig_approver_img}&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
    <div>ลงชื่อ ....................................................... ผู้อนุญาต</div>
    <div>( {$fixed_approver_name} )&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</div>
    <div>วันที่ {$today_th}&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</div>
  </div>

  <div class="mt10 small">(ผู้อนุญาตสั่งใช้รถคณะ ได้แก่ หัวหน้าสำนักงาน / รองคณบดีฝ่ายบริหาร / คณบดี)</div>
  <div class="line long" style="height:0;"></div><br>

  <div class="center">
    <div style="font-weight:bold;">บันทึกผู้ขับรถและยามรักษาการณ์</div>
  </div>
  <p>
    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;รถหมายเลขทะเบียน {$plate} วันที่ $start_th 
    เวลาออก {$start_time} น.  เลขกิโลเมตร เมื่อออก {$odo_start}
    เวลากลับ {$end_time} น. เลขกิโลเมตร เมื่อกลับ {$odo_end} รวมระยะทาง {$total_km} กิโลเมตร
  </p>

  <div style="text-align:right">
    {$sig_driver_img}&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
    <div>ลงชื่อ ....................................................... พนักงานขับรถ</div>
    <div>( {$driver_name} )&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</div>
    <div>วันที่ {$today_th}&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</div>
  </div>

  <div class="small" style="text-align:right; margin-top:10px;">FM-อาคาร-02-01</div>

</body>
</html>
HTML;

/* ---------- สร้าง PDF ---------- */
$mpdf->WriteHTML($html);
$mpdf->Output("trip_form_{$trip_id}.pdf", \Mpdf\Output\Destination::INLINE);







