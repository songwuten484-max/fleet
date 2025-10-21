<?php
// public/bookings_create.php
declare(strict_types=1);

/* ---- Session (ต้องใช้ชื่อเดียวกับ login/SSO) ---- */
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
  header('Location: index.php'); exit;
}

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
set_exception_handler(function($e){
  http_response_code(500);
  echo "<pre style='white-space:pre-wrap;color:#a00;background:#fee;border:1px solid #fbb;padding:10px'>";
  echo "PHP Fatal Error:\n", $e->getMessage(), "\n\n";
  echo $e->getFile(), ":", $e->getLine(), "\n\n";
  echo $e->getTraceAsString();
  echo "</pre>";
  exit;
});
set_error_handler(function($no,$str,$file,$line){
  throw new ErrorException($str, 0, $no, $file, $line);
});

require_once __DIR__.'/../inc/config.php';
require_once __DIR__.'/../inc/layout.php';
require_once __DIR__.'/../inc/helpers.php';
require_once __DIR__.'/../inc/db.php';
require_once __DIR__.'/../inc/line.php';

$pdo = db();
$u   = require_role(['USER','ADMIN','APPROVER_L1','APPROVER_L2','DRIVER']);

/* โหลดเฉพาะรถที่ active */
$vehicles = $pdo->query("SELECT id, plate_no, brand_model FROM vehicles WHERE active=1 ORDER BY plate_no")->fetchAll();

$flash = ''; $error = ''; $conflicts = [];

/** ---- helper: upload optional request file ---- */
function handle_request_file_upload(?array $f): ?string {
  if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
  if (!isset($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) {
    throw new Exception('อัปโหลดไฟล์ไม่สำเร็จ');
  }
  if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    throw new Exception('เกิดข้อผิดพลาดระหว่างอัปโหลดไฟล์ (code '.$f['error'].')');
  }
  $max = 10 * 1024 * 1024;
  if (($f['size'] ?? 0) > $max) throw new Exception('ไฟล์มีขนาดเกิน 10MB');

  $allowed_ext  = ['pdf','jpg','jpeg','png','doc','docx'];
  $allowed_mime = [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
  ];

  $name = $f['name'] ?? 'file';
  $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if (!in_array($ext, $allowed_ext, true)) {
    throw new Exception('ชนิดไฟล์ไม่รองรับ (อนุญาต: pdf, jpg, jpeg, png, doc, docx)');
  }

  $fi = finfo_open(FILEINFO_MIME_TYPE);
  $mime = $fi ? (finfo_file($fi, $f['tmp_name']) ?: '') : '';
  if ($fi) finfo_close($fi);
  if ($mime && !in_array($mime, $allowed_mime, true)) {
    if (!($ext === 'docx' && $mime === 'application/zip')) {
      throw new Exception('MIME type ของไฟล์ไม่ถูกต้อง');
    }
  }

  $dir = __DIR__ . '/uploads/booking_refs';
  if (!is_dir($dir)) {
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
      throw new Exception('ไม่สามารถสร้างโฟลเดอร์อัปโหลดได้');
    }
  }
  $ts = time();
  $rand = random_int(1000, 9999);
  $safe_base = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($name, PATHINFO_FILENAME));
  $newname  = sprintf('ref_%d_%d_%s.%s', $ts, $rand, $safe_base, $ext);
  $dest_abs = $dir . '/' . $newname;
  if (!move_uploaded_file($f['tmp_name'], $dest_abs)) {
    throw new Exception('ย้ายไฟล์อัปโหลดไม่สำเร็จ');
  }
  return 'uploads/booking_refs/' . $newname;
}

/* ===== ตรวจ “การประเมินค้างอยู่” สำหรับรถใช้งานภายนอก =====
   เงื่อนไข:
   - การจองเป็นของผู้ใช้คนนี้
   - approval_required = 1 (ภายนอก)
   - มีทริปและสิ้นสุดแล้ว
   - ยังไม่มีรีวิว (driver_reviews) จากผู้ใช้นี้
*/
$pendingReviewsStmt = $pdo->prepare("
SELECT 
  b.id AS booking_id, b.start_datetime, b.end_datetime,
  v.plate_no, v.brand_model,
  t.id AS trip_id, t.actual_end
FROM bookings b
JOIN vehicles v ON v.id = b.vehicle_id
JOIN trips t ON t.booking_id = b.id
WHERE b.user_id = ?
  AND b.approval_required = 1
  AND t.actual_end IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM driver_reviews r
    WHERE r.booking_id = b.id AND r.rater_user_id = ?
  )
ORDER BY b.start_datetime DESC
");
$pendingReviewsStmt->execute([$u['id'], $u['id']]);
$pendingReviews = $pendingReviewsStmt->fetchAll();
$hasPendingReview = !empty($pendingReviews);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // ถ้ามีการประเมินค้างอยู่ → บล็อกการจองใหม่
  if ($hasPendingReview) {
    $error = 'ไม่สามารถจองรถได้ เนื่องจากคุณมีแบบประเมินคนขับของ “รถใช้งานภายนอก” ที่ค้างอยู่ กรุณาประเมินให้เรียบร้อยก่อน';
  }

  $vehicle_id  = (int)($_POST['vehicle_id'] ?? 0);
  $purpose     = trim((string)($_POST['purpose'] ?? ''));
  $destination = trim((string)($_POST['destination'] ?? ''));
  $start       = (string)($_POST['start_datetime'] ?? '');
  $end         = (string)($_POST['end_datetime'] ?? '');
  $request_file_path = null;

  if (!$error) {
    if (!$vehicle_id || !$start || !$end) {
      $error = 'กรุณากรอกข้อมูลให้ครบ';
    } elseif (strtotime($end) <= strtotime($start)) {
      $error = 'เวลาสิ้นสุดต้องมากกว่าเวลาเริ่มต้น';
    }
  }

  // ตรวจชน
  if (!$error) {
    $sql = "
      SELECT b.id, b.start_datetime, b.end_datetime, b.status
      FROM bookings b
      WHERE b.vehicle_id = ?
        AND b.status IN ('PENDING','APPROVED_L1','APPROVED')
        AND NOT (b.end_datetime <= ? OR b.start_datetime >= ?)
        AND (
              EXISTS (
                SELECT 1 FROM trips t1
                WHERE t1.booking_id = b.id
                  AND (t1.actual_end IS NULL OR t1.actual_end = '0000-00-00 00:00:00')
              )
              OR
              (
                NOT EXISTS (SELECT 1 FROM trips t0 WHERE t0.booking_id = b.id)
                AND NOW() < b.end_datetime
              )
            )
      ORDER BY b.start_datetime ASC
    ";
    $q = $pdo->prepare($sql);
    $q->execute([$vehicle_id, $start, $end]);
    $conflicts = $q->fetchAll();

    if ($conflicts) {
      $error = 'ช่วงเวลานี้รถไม่ว่าง (ชนกับการจองที่มีอยู่)';
    }
  }

  // ไฟล์แนบ (ถ้าไม่ error)
  if (!$error) {
    try {
      $request_file_path = handle_request_file_upload($_FILES['request_file'] ?? null);
    } catch (Throwable $e) {
      $error = $e->getMessage();
    }
  }

  if (!$error) {
    // ข้อมูลรถ
    $vehStmt = $pdo->prepare("SELECT plate_no, brand_model, is_campus_only FROM vehicles WHERE id=? AND active=1");
    $vehStmt->execute([$vehicle_id]);
    $veh = $vehStmt->fetch();

    if (!$veh) {
      $error = 'ไม่พบรถที่เลือก หรือรถถูกปิดใช้งาน';
    } else {
      $isInternal = !empty($veh['is_campus_only']); // ภายในมหาวิทยาลัย?

      if ($isInternal) {
        // รถใช้งานภายใน: อนุมัติทันที
        $stmt = $pdo->prepare("
          INSERT INTO bookings
            (user_id, vehicle_id, purpose, start_datetime, end_datetime, destination,
             approval_required, status, approval_stage, request_file)
          VALUES (?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
          $u['id'],
          $vehicle_id,
          $purpose,
          $start,
          $end,
          $destination,
          0,              // approval_required
          'APPROVED',     // อนุมัติอัตโนมัติ
          0,              // approval_stage
          $request_file_path
        ]);

        $flash = 'จองรถสำเร็จ (รถใช้ภายใน — อนุมัติอัตโนมัติ)';

        $admins = $pdo->query("SELECT line_user_id, name FROM users WHERE role='ADMIN' AND line_user_id IS NOT NULL")->fetchAll();
        $msg  = "มีการจองรถ (ภายในมหาวิทยาลัย)\n";
        $msg .= "ผู้ขอ: {$u['name']}\n";
        $msg .= "รถ: {$veh['plate_no']} • {$veh['brand_model']}\n";
        $msg .= "วัตถุประสงค์: {$purpose}\n";
        $msg .= "ช่วงเวลา: {$start} → {$end}\n";
        if ($destination) $msg .= "ปลายทาง: {$destination}\n";
        if ($request_file_path) $msg .= "(มีไฟล์แนบต้นเรื่อง)";
        foreach($admins as $a){
          line_push($a['line_user_id'], [ line_text($msg) ]);
        }
      } else {
        // รถใช้งานภายนอก: ต้องอนุมัติชั้น 1 (เริ่ม PENDING)
        $stmt = $pdo->prepare("
          INSERT INTO bookings
            (user_id, vehicle_id, purpose, start_datetime, end_datetime, destination, approval_required, request_file)
          VALUES (?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
          $u['id'],
          $vehicle_id,
          $purpose,
          $start,
          $end,
          $destination,
          1,
          $request_file_path
        ]);

        $flash = 'ส่งคำขอใช้รถแล้ว รอการอนุมัติ';

        $admins = $pdo->query("
          SELECT line_user_id FROM users 
          WHERE role IN ('ADMIN','APPROVER_L1') AND line_user_id IS NOT NULL
        ")->fetchAll();
        $msg  = "มีคำขอใช้รถใหม่จาก {$u['name']} (ต้องการอนุมัติ 1 ชั้น)\n";
        $msg .= "รถ: {$veh['plate_no']} • {$veh['brand_model']}\n";
        $msg .= "วัตถุประสงค์: {$purpose}\n";
        $msg .= "ช่วงเวลา: {$start} → {$end}\n";
        if ($destination) $msg .= "ปลายทาง: {$destination}\n";
        if ($request_file_path) $msg .= "(มีไฟล์แนบต้นเรื่อง)";
        foreach($admins as $a){
          line_push($a['line_user_id'], [ line_text($msg) ]);
        }
      }
    }
  }
}

render_header('ขอใช้รถ • Fleet');
?>
  <?php if($flash): ?><div class="flash"><?=h($flash)?></div><?php endif; ?>
  <?php if($error): ?>
    <div class="flash" style="background:#ffecec;border-color:#ffcccc;color:#a33;">
      <?=h($error)?>
      <?php if($conflicts): ?> — รายการชน:
        <?php foreach($conflicts as $c): ?>
          <div>#<?=h($c['id'])?> (<?=h($c['start_datetime'])?> → <?=h($c['end_datetime'])?>) [<?=h($c['status'])?>]</div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($hasPendingReview): ?>
    <div class="flash" style="background:#ffecec;border-color:#ffcccc;color:#a33;">
      คุณมีแบบประเมินคนขับของ “รถใช้งานภายนอก” ที่ค้างอยู่ กรุณาประเมินให้เรียบร้อยก่อนจึงจะสามารถจองรถได้
      <div style="margin-top:6px;font-size:13px;">
        รายการที่ต้องประเมิน:
        <?php foreach($pendingReviews as $pr): ?>
          <div>#<?=h($pr['booking_id'])?> (<?=h($pr['start_datetime'])?> → <?=h($pr['end_datetime'])?>) — <?=h($pr['plate_no'].' • '.$pr['brand_model'])?></div>
        <?php endforeach; ?>
      </div>
      <div style="margin-top:8px;">
        <a class="btn" href="driver_review.php">ไปหน้า “ประเมินคนขับรถ”</a>
      </div>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>ขอใช้รถ</h2>
    <form method="post" id="form-book" enctype="multipart/form-data">
      <?php echo form_brand_badge(); ?>

      <label>เลือกรถ</label>
      <select name="vehicle_id" id="vehicle_id" required <?= $hasPendingReview?'disabled':'' ?>>
        <option value="">-- เลือก --</option>
        <?php foreach($vehicles as $v): ?>
          <option value="<?=$v['id']?>"><?=h($v['plate_no'].' • '.$v['brand_model'])?></option>
        <?php endforeach; ?>
      </select>

      <label>วัตถุประสงค์</label>
      <input name="purpose" required <?= $hasPendingReview?'disabled':'' ?>>

      <label>ปลายทาง</label>
      <input name="destination" <?= $hasPendingReview?'disabled':'' ?>>

      <label>เริ่มใช้ (วันที่และเวลา)</label>
      <input type="datetime-local" name="start_datetime" id="start_datetime" required <?= $hasPendingReview?'disabled':'' ?>>

      <label>สิ้นสุด (วันที่และเวลา)</label>
      <input type="datetime-local" name="end_datetime" id="end_datetime" required <?= $hasPendingReview?'disabled':'' ?>>

      <label>แนบไฟล์ต้นเรื่อง (ไม่บังคับ)</label>
      <input type="file" name="request_file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" <?= $hasPendingReview?'disabled':'' ?>>

      <div style="font-size:12px;color:#555;margin:6px 0 0;">
        อนุญาตไฟล์: PDF, JPG/PNG, DOC/DOCX ขนาดไม่เกิน 10 MB
      </div>

      <div id="avail" class="flash" style="display:none;margin-top:8px"></div>

      <button <?= $hasPendingReview?'disabled title="กรุณาประเมินการจองรถภายนอกที่ค้างอยู่ก่อน"':'' ?>>ส่งคำขอ</button>
      <a class="btn secondary" href="dashboard.php">กลับ</a>
    </form>
  </div>

<script>
const hasPending = <?= $hasPendingReview ? 'true':'false' ?>;
const elVid = document.getElementById('vehicle_id');
const elS   = document.getElementById('start_datetime');
const elE   = document.getElementById('end_datetime');
const panel = document.getElementById('avail');

async function checkAvail() {
  if (hasPending) { panel.style.display='none'; return; }
  const vid = elVid?.value, s = elS?.value, e = elE?.value;
  if (!vid || !s || !e) { panel.style.display='none'; return; }
  try {
    const q = new URLSearchParams({vehicle_id:vid, start:s, end:e}).toString();
    const r = await fetch('api_check_availability.php?'+q);
    const data = await r.json();
    if (!data.ok) { panel.style.display='none'; return; }
    panel.style.display='block';
    if (data.available) {
      panel.style.background = '#eaf6ff';
      panel.style.borderColor = '#bfe3ff';
      panel.style.color = '#155e75';
      panel.innerHTML = 'ช่วงเวลานี้: รถ <b>ว่าง</b>';
    } else {
      panel.style.background = '#ffecec';
      panel.style.borderColor = '#ffcccc';
      panel.style.color = '#a33';
      let html = 'ช่วงเวลานี้: รถ <b>ไม่ว่าง</b> (ชนกับรายการ ';
      html += data.conflicts.map(c=>`#${c.id} [${c.status}]`).join(', ');
      html += ')';
      panel.innerHTML = html;
    }
  } catch (e) {
    panel.style.display='none';
  }
}

['change','input'].forEach(ev=>{
  elVid?.addEventListener(ev, checkAvail);
  elS?.addEventListener(ev, checkAvail);
  elE?.addEventListener(ev, checkAvail);
});
</script>

<?php render_footer(); ?>
