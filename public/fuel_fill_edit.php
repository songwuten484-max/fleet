<?php
// public/fuel_fill_edit.php
declare(strict_types=1);

/* ===== Session: ใช้ชื่อเดียวกันทุกไฟล์ ===== */
session_name('FLEETSESSID');
session_set_cookie_params([
  'lifetime' => 0,
  'path'     => '/',
  'domain'   => 'roombooking.fba.kmutnb.ac.th',
  'secure'   => true,
  'httponly' => true,
  'samesite' => 'Lax',
]);
session_start();
if (empty($_SESSION['user'])) {
  header('Location: index.php');
  exit;
}

require_once __DIR__.'/../inc/config.php';
require_once __DIR__.'/../inc/layout.php';
require_once __DIR__.'/../inc/helpers.php';
require_once __DIR__.'/../inc/db.php';

$pdo = db();
$u   = require_role(['ADMIN','APPROVER_L1','APPROVER_L2','DRIVER']);

/* ---------- Helper: URL ของรูปใบเสร็จ ---------- */
function public_base_url(): string {
  // หาจากตำแหน่งปัจจุบัน เช่น /FBA_fleet/public
  $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
  return $base === '' ? '/' : $base;
}
function slip_url(string $file = null): ?string {
  if (!$file) return null;
  if (preg_match('~^(https?://|data:)~i', $file)) return $file;

  $base   = public_base_url();
  $pubDir = str_replace('\\', '/', realpath(__DIR__));

  $candidates = [
    ltrim($file, '/'),
    'uploads/fuel_slips/' . ltrim($file, '/'),
    'uploads/fuel_slips/' . basename($file),
  ];
  foreach ($candidates as $rel) {
    $abs = $pubDir . '/' . $rel;
    if (is_file($abs)) {
      return $base . '/' . $rel;
    }
  }
  return $base . '/' . ltrim($file, '/');
}

/* ---------- Helper: จัดการอัปโหลด ---------- */
function ensure_dir(string $dir): void {
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
}
function move_receipt_upload(array $file, ?string $oldPath): ?string {
  if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
    return $oldPath;
  }

  $info = @getimagesize($file['tmp_name']);
  if (!$info) throw new Exception('ไฟล์ใบเสร็จไม่ใช่รูปภาพที่ถูกต้อง');
  $mime = $info['mime'] ?? '';
  $ext  = match($mime) {
    'image/png' => 'png',
    'image/jpeg', 'image/jpg' => 'jpg',
    'image/gif' => 'gif',
    default => throw new Exception('รองรับไฟล์เฉพาะ PNG, JPG, GIF')
  };

  $destDir = realpath(__DIR__) . '/uploads/fuel_slips';
  ensure_dir($destDir);
  $newName = 'slip_'.date('Ymd_His').'_'.bin2hex(random_bytes(3)).'.'.$ext;
  $destAbs = $destDir.'/'.$newName;

  if (!move_uploaded_file($file['tmp_name'], $destAbs)) {
    throw new Exception('ไม่สามารถบันทึกไฟล์ใบเสร็จได้');
  }

  // ลบของเก่า (ถ้ามี)
  if ($oldPath) {
    $oldAbs = realpath(__DIR__.'/'.ltrim($oldPath,'/'));
    if ($oldAbs && is_file($oldAbs) && str_contains($oldAbs, realpath(__DIR__.'/uploads/fuel_slips'))) {
      @unlink($oldAbs);
    }
  }

  return 'uploads/fuel_slips/'.$newName;
}

/* ===== รับ id ===== */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); echo "Missing id"; exit; }

/* ===== ดึงข้อมูลเดิม ===== */
$stmt = $pdo->prepare("
  SELECT fct.*, v.plate_no, v.brand_model
  FROM fuel_card_transactions fct
  JOIN vehicles v ON v.id=fct.vehicle_id
  WHERE fct.id = ?
  LIMIT 1
");
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) { http_response_code(404); echo "ไม่พบข้อมูล"; exit; }

/* ===== ตรวจสิทธิ์ ===== */
$is_manager = in_array($u['role'], ['ADMIN','APPROVER_L1','APPROVER_L2'], true);
if (!$is_manager && $u['role']==='DRIVER' && (int)$row['driver_id'] !== (int)$u['id']) {
  http_response_code(403);
  echo "คุณไม่มีสิทธิ์แก้ไขรายการนี้";
  exit;
}

/* ===== โหลดรายชื่อรถ ===== */
$vehicles = $pdo->query("SELECT id, plate_no, brand_model FROM vehicles WHERE active=1 ORDER BY plate_no")->fetchAll();

$msg=''; $err='';

/* ===== เมื่อกดบันทึก ===== */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  try {
    $vehicle_id      = (int)($_POST['vehicle_id'] ?? $row['vehicle_id']);
    $tx_date         = $_POST['tx_date'] ?? $row['tx_date'];
    $liters          = $_POST['liters']!=='' ? (float)$_POST['liters'] : null;
    $price_per_liter = $_POST['price_per_liter']!=='' ? (float)$_POST['price_per_liter'] : null;
    $amount          = $_POST['amount']!=='' ? (float)$_POST['amount'] : null;
    $odometer        = $_POST['odometer']!=='' ? (int)$_POST['odometer'] : null;
    $receipt_no      = $_POST['receipt_no'] ?? null;
    $notes           = $_POST['notes'] ?? null;

    if ($amount===null && $liters!==null && $price_per_liter!==null)
      $amount = $liters * $price_per_liter;

    $receipt_file = $row['receipt_file'];
    if (!empty($_FILES['receipt_file'])) {
      $receipt_file = move_receipt_upload($_FILES['receipt_file'], $row['receipt_file']);
    }

    $upd = $pdo->prepare("
      UPDATE fuel_card_transactions
      SET vehicle_id=?, tx_date=?, liters=?, price_per_liter=?, amount=?, odometer=?, receipt_no=?, receipt_file=?, notes=?
      WHERE id=?
    ");
    $upd->execute([$vehicle_id,$tx_date,$liters,$price_per_liter,$amount,$odometer,$receipt_no,$receipt_file,$notes,$id]);

    $msg = 'บันทึกการแก้ไขเรียบร้อย';
    $stmt->execute([$id]);
    $row = $stmt->fetch();
  } catch(Throwable $e) { $err=$e->getMessage(); }
}

render_header('แก้ไขรายการเติมน้ำมัน • Fleet');
?>

<?php if($msg): ?><div class="flash"><?=h($msg)?></div><?php endif; ?>
<?php if($err): ?><div class="flash" style="background:#ffecec;border-color:#ffcccc;color:#a33;"><?=h($err)?></div><?php endif; ?>

<div class="card">
  <h2>แก้ไขรายการ #<?=h($row['id'])?></h2>
  <form method="post" enctype="multipart/form-data" style="display:grid;gap:8px;max-width:720px;">
    <label>วันที่ทำรายการ</label>
    <input type="date" name="tx_date" value="<?=h(substr($row['tx_date'],0,10))?>" required>

    <label>รถ</label>
    <select name="vehicle_id" required>
      <?php foreach($vehicles as $v): ?>
        <option value="<?=$v['id']?>" <?=$v['id']==$row['vehicle_id']?'selected':''?>>
          <?=h($v['plate_no'].' • '.$v['brand_model'])?>
        </option>
      <?php endforeach; ?>
    </select>

    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;">
      <div><label>ลิตร</label><input type="number" step="0.001" name="liters" value="<?=h($row['liters'])?>"></div>
      <div><label>ราคา/ลิตร</label><input type="number" step="0.01" name="price_per_liter" value="<?=h($row['price_per_liter'])?>"></div>
      <div><label>ยอดเงิน</label><input type="number" step="0.01" name="amount" value="<?=h($row['amount'])?>"></div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
      <div><label>เลขไมล์</label><input type="number" name="odometer" value="<?=h($row['odometer'])?>"></div>
      <div><label>เลขที่ใบเสร็จ</label><input name="receipt_no" value="<?=h($row['receipt_no'])?>"></div>
    </div>

    <label>แนบรูปใบเสร็จ (PNG/JPG/GIF)</label>
    <input type="file" name="receipt_file" accept="image/png,image/jpeg,image/jpg,image/gif">

    <?php if (!empty($row['receipt_file'])): ?>
      <?php $thumb = slip_url((string)$row['receipt_file']); ?>
      <div style="margin:6px 0;">
        <div style="font-size:12px;color:#666;">ใบเสร็จปัจจุบัน:</div>
        <div style="border:1px solid #ddd;padding:6px;display:inline-block;background:#fff;">
          <a href="<?=h($thumb)?>" target="_blank" title="เปิดรูปขนาดเต็ม">
            <img src="<?=h($thumb)?>" alt="slip" style="max-width:320px;max-height:200px;">
          </a>
        </div>
      </div>
    <?php endif; ?>

    <label>หมายเหตุ</label>
    <textarea name="notes" rows="3"><?=h($row['notes'])?></textarea>

    <div style="display:flex;gap:8px;margin-top:8px;">
      <button type="submit">บันทึก</button>
      <a class="btn secondary" href="fuel_fills.php">กลับ</a>
    </div>
  </form>
</div>

<?php render_footer(); ?>
