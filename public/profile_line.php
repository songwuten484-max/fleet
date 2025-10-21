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

require_once __DIR__.'/../inc/config.php';
require_once __DIR__.'/../inc/layout.php';
require_once __DIR__.'/../inc/helpers.php';
require_once __DIR__.'/../inc/db.php';

$u = require_login();
$pdo = db();

$msg = ''; $err='';

// ===== Helpers =====
function ensure_dir($dir){
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }
}

function save_png_from_upload(array $f, string $destPng): bool {
  if (!isset($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) return false;

  // ตรวจชนิดรูป
  $info = @getimagesize($f['tmp_name']);
  if (!$info) throw new Exception('ไฟล์รูปไม่ถูกต้อง');
  $mime = $info['mime'] ?? '';
  if (!in_array($mime, ['image/png','image/jpeg','image/jpg','image/gif'])) {
    throw new Exception('รองรับเฉพาะ PNG / JPG / GIF');
  }

  // โหลดเป็น GD image
  switch ($mime) {
    case 'image/png':  $im = imagecreatefrompng($f['tmp_name']);  break;
    case 'image/jpeg':
    case 'image/jpg':  $im = imagecreatefromjpeg($f['tmp_name']); break;
    case 'image/gif':  $im = imagecreatefromgif($f['tmp_name']);  break;
    default: $im = null;
  }
  if (!$im) throw new Exception('ไม่สามารถอ่านรูปได้');

  // Resize แบบคงสัดส่วน (จำกัดกว้างไม่เกิน 900px, สูงไม่เกิน 300px)
  $w = imagesx($im); $h = imagesy($im);
  $maxW = 900; $maxH = 300;
  $scale = min($maxW / $w, $maxH / $h, 1.0);
  $nw = (int)floor($w * $scale); $nh = (int)floor($h * $scale);

  $out = imagecreatetruecolor($nw, $nh);
  imagesavealpha($out, true);
  $trans = imagecolorallocatealpha($out, 0, 0, 0, 127);
  imagefill($out, 0, 0, $trans);

  imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);

  $ok = imagepng($out, $destPng, 6);

  imagedestroy($im);
  imagedestroy($out);
  return $ok;
}

function public_base_url(): string {
  $base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');
  return $base === '' ? '/' : $base;
}

// ===== Unlink LINE =====
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['unlink'])){
  $pdo->prepare("UPDATE users SET line_user_id=NULL WHERE id=?")->execute([$u['id']]);
  $msg = 'ยกเลิกการเชื่อม LINE เรียบร้อย';
}

// ===== Generate token =====
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['gen'])){
  $token = strtoupper(bin2hex(random_bytes(3))); // 6 hex chars
  $pdo->prepare("INSERT INTO link_tokens (user_id, token) VALUES (?,?)")->execute([$u['id'],$token]);
  $msg = 'สร้างโค้ดเชื่อมแล้ว: '.$token.' (พิมพ์ในแชท LINE กับ OA)';
}

// ===== อัปโหลด/ลบลายเซ็น =====
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['sig_action'])) {
  try {
    if ($_POST['sig_action'] === 'upload') {
      if (empty($_FILES['signature']) || $_FILES['signature']['error']!==UPLOAD_ERR_OK) {
        throw new Exception('กรุณาเลือกไฟล์ลายเซ็น (PNG/JPG)');
      }

      $pubDir  = realpath(__DIR__);
      $sigDir  = $pubDir.'/uploads/signatures';
      ensure_dir($sigDir);

      $filename = 'user_'.$u['id'].'_signature.png';
      $destAbs  = $sigDir.'/'.$filename;

      save_png_from_upload($_FILES['signature'], $destAbs);

      $relPath = 'uploads/signatures/'.$filename;
      $pdo->prepare("UPDATE users SET signature_file=? WHERE id=?")->execute([$relPath, $u['id']]);
      $msg = 'บันทึกลายเซ็นเรียบร้อย';
    }

    if ($_POST['sig_action'] === 'delete') {
      $q = $pdo->prepare("SELECT signature_file FROM users WHERE id=?");
      $q->execute([$u['id']]);
      $cur = $q->fetchColumn();
      if ($cur) {
        $abs = realpath(__DIR__.'/'.$cur);
        if ($abs && is_file($abs)) @unlink($abs);
      }
      $pdo->prepare("UPDATE users SET signature_file=NULL WHERE id=?")->execute([$u['id']]);
      $msg = 'ลบลายเซ็นเรียบร้อย';
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

// ===== Load current status =====
$me = $pdo->prepare("SELECT line_user_id, signature_file FROM users WHERE id=?");
$me->execute([$u['id']]);
list($line_id, $signature_file) = array_values($me->fetch() ?: ['','']);

$token_rows = $pdo->prepare("SELECT token, created_at FROM link_tokens WHERE user_id=? AND used_at IS NULL ORDER BY id DESC LIMIT 5");
$token_rows->execute([$u['id']]);
$tokens = $token_rows->fetchAll();

render_header('เชื่อม LINE OA • Fleet');
?>

  <?php if($msg): ?><div class="flash"><?=h($msg)?></div><?php endif; ?>
  <?php if($err): ?><div class="flash" style="background:#ffecec;border-color:#ffcccc;color:#a33;"><?=h($err)?></div><?php endif; ?>

  <div class="card">
    <h2>สถานะการเชื่อม</h2>
    <p>LINE User ID: <b><?= $line_id ? h($line_id) : '<i>ยังไม่เชื่อม</i>' ?></b></p>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap">
      <button name="gen" value="1" type="submit">สร้างโค้ดเชื่อม</button>
      <?php if($line_id): ?>
        <button class="secondary" name="unlink" value="1" type="submit" onclick="return confirm('ยืนยันยกเลิกการเชื่อม LINE?')">ยกเลิกการเชื่อม</button>
      <?php endif; ?>
      <a class="btn secondary" href="dashboard.php">กลับ</a>
    </form>

    <div style="margin-top:10px">
      <h3>วิธีเชื่อมบัญชี</h3>
      <ol>
        <li>กดปุ่ม <b>สร้างโค้ดเชื่อม</b> จะได้โค้ด เช่น <code>ABC123</code></li>
        <li>เพิ่มเพื่อน LINE OA ของระบบ (สแกน QR ด้านล่าง)</li>
        <li>พิมพ์ข้อความไปหา OA: <code>LINK &lt;โค้ด&gt;</code> เช่น <code>LINK ABC123</code></li>
        <li>ระบบจะบันทึก LINE User ID เข้ากับบัญชีของคุณอัตโนมัติ</li>
      </ol>

      <!-- 🔽 เพิ่มรูป QR Code LINE OA -->
      <div style="text-align:center; margin-top:14px;">
        <img src="../assets/Qr_line.png" alt="QR Code LINE OA" style="max-width:220px; border:4px solid #e2e8f0; border-radius:12px;">
        <div style="font-size:14px; color:#555; margin-top:6px;">สแกน QR เพื่อเพิ่มเพื่อน LINE OA ของระบบ</div>
      </div>
    </div>

    <?php if($tokens): ?>
      <div style="margin-top:10px">
        <h3>โค้ดที่ยังใช้ได้</h3>
        <ul>
          <?php foreach($tokens as $t): ?>
            <li><code><?=h($t['token'])?></code> • ออกเมื่อ <?=h($t['created_at'])?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>จัดการลายเซ็นต์</h2>

    <?php if($signature_file): ?>
      <div style="display:flex; gap:16px; align-items:flex-start; flex-wrap:wrap;">
        <div>
          <div style="font-weight:bold; margin-bottom:6px;">ลายเซ็นปัจจุบัน</div>
          <div style="border:1px solid #ddd; padding:8px; background:#fff;">
            <img src="<?= h(public_base_url().'/'.ltrim($signature_file,'/')) ?>" alt="signature" style="max-width:420px; max-height:180px;">
          </div>
          <div style="color:#666; font-size:12px; margin-top:6px;">ไฟล์: <?= h($signature_file) ?></div>
        </div>
        <form method="post" onsubmit="return confirm('ลบลายเซ็นปัจจุบัน?')">
          <input type="hidden" name="sig_action" value="delete">
          <button class="secondary">ลบลายเซ็น</button>
        </form>
      </div>
      <hr style="margin:14px 0">
      <div><b>อัปโหลดทับ</b> (รองรับ PNG/JPG, พื้นหลังโปร่งใสจะแสดงผลสวยที่สุด)</div>
    <?php else: ?>
      <p style="margin-top:0;">ยังไม่มีลายเซ็นในระบบ — อัปโหลดไฟล์ PNG/JPG เพื่อใช้แนบในเอกสาร PDF</p>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" style="margin-top:8px;">
      <input type="hidden" name="sig_action" value="upload">
      <input type="file" name="signature" accept="image/png,image/jpeg,image/jpg,image/gif" required>
      <div style="color:#666; font-size:12px; margin-top:6px;">
        * ระบบจะย่อรูปอัตโนมัติ (กว้างไม่เกิน 900px สูงไม่เกิน 300px) และบันทึกเป็น PNG โปร่งใส
      </div>
      <div style="margin-top:8px;">
        <button type="submit">บันทึกลายเซ็น</button>
      </div>
    </form>

    <div style="margin-top:10px; color:#444;">
      <b>ระบบใช้ลายเซ็นของท่านในการขอใช้รถราชการเท่านั้น</b><br>
    </div>
  </div>

<?php render_footer(); ?>
