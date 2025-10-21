<?php
// public/driver_review.php
declare(strict_types=1);

session_name('FLEETSESSID');
session_start();

require_once __DIR__.'/../inc/config.php';
require_once __DIR__.'/../inc/layout.php';
require_once __DIR__.'/../inc/helpers.php';
require_once __DIR__.'/../inc/db.php';

$pdo = db();
$u   = require_role(['USER','ADMIN','APPROVER_L1','APPROVER_L2','DRIVER']);

/* --- สร้างตารางถ้ายังไม่มี --- */
$pdo->exec("
CREATE TABLE IF NOT EXISTS driver_reviews (
  id INT AUTO_INCREMENT PRIMARY KEY,
  booking_id INT NOT NULL,
  trip_id INT DEFAULT NULL,
  vehicle_id INT NOT NULL,
  driver_id INT DEFAULT NULL,
  rater_user_id INT NOT NULL,
  rating TINYINT NOT NULL,
  comment TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_booking (booking_id),
  KEY idx_driver (driver_id),
  KEY idx_vehicle (vehicle_id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* --- CSRF token --- */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$flash=''; $error='';

/* --- โหลด “การจองที่สิ้นสุดแล้วและยังไม่ได้รีวิว” ของผู้ใช้ (เฉพาะรถใช้ภายนอก) ---
   เงื่อนไข:
   - b.user_id = current user
   - b.approval_required = 1   (ตีความว่า "ใช้งานภายนอก")
   - มีทริปและสิ้นสุดแล้ว (t.actual_end NOT NULL)
   - ยังไม่มีรีวิวโดยผู้ใช้คนนี้ (NOT EXISTS driver_reviews booking_id+rater_user_id)
*/
$sql = "
SELECT 
  b.id AS booking_id, b.start_datetime, b.end_datetime, 
  v.id AS vehicle_id, v.plate_no, v.brand_model,
  t.id AS trip_id, t.driver_id,
  u2.name AS driver_name
FROM bookings b
JOIN vehicles v ON v.id = b.vehicle_id
JOIN trips t ON t.booking_id = b.id
LEFT JOIN users u2 ON u2.id = t.driver_id
WHERE b.user_id = ?
  AND b.approval_required = 1
  AND t.actual_end IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM driver_reviews r 
    WHERE r.booking_id = b.id AND r.rater_user_id = ?
  )
ORDER BY b.start_datetime DESC
LIMIT 200";
$q = $pdo->prepare($sql);
$q->execute([$u['id'], $u['id']]);
$eligible = $q->fetchAll();

/* --- เมื่อส่งฟอร์ม --- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  try {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
      throw new Exception('CSRF token ไม่ถูกต้อง');
    }
    $booking_id = (int)($_POST['booking_id'] ?? 0);
    $rating     = (int)($_POST['rating'] ?? 0);
    $comment    = trim((string)($_POST['comment'] ?? ''));

    if (!$booking_id || $rating < 1 || $rating > 5) {
      throw new Exception('กรุณาเลือกการจองและให้คะแนน 1–5');
    }

    // ตรวจสอบสิทธิ์และสถานะการจองซ้ำอีกครั้ง (ต้องเป็นภายนอกเท่านั้น)
    $chk = $pdo->prepare("
      SELECT 
        b.id AS booking_id, b.user_id, b.vehicle_id, b.approval_required,
        v.plate_no, v.brand_model,
        t.id AS trip_id, t.driver_id, t.actual_end,
        u2.name AS driver_name
      FROM bookings b
      JOIN vehicles v ON v.id = b.vehicle_id
      JOIN trips t ON t.booking_id = b.id
      LEFT JOIN users u2 ON u2.id = t.driver_id
      WHERE b.id = ? AND b.user_id = ? AND b.approval_required = 1 AND t.actual_end IS NOT NULL
      LIMIT 1
    ");
    $chk->execute([$booking_id, $u['id']]);
    $row = $chk->fetch();
    if (!$row) {
      throw new Exception('ไม่พบการจองของคุณ (รถภายนอก) หรือทริปยังไม่สิ้นสุด');
    }

    // กันรีวิวซ้ำ
    $du = $pdo->prepare("SELECT 1 FROM driver_reviews WHERE booking_id=? AND rater_user_id=?");
    $du->execute([$booking_id, $u['id']]);
    if ($du->fetch()) {
      throw new Exception('คุณได้ประเมินการจองนี้แล้ว');
    }

    // บันทึก
    $ins = $pdo->prepare("
      INSERT INTO driver_reviews
      (booking_id, trip_id, vehicle_id, driver_id, rater_user_id, rating, comment)
      VALUES (?,?,?,?,?,?,?)
    ");
    $ins->execute([
      $row['booking_id'], $row['trip_id'], $row['vehicle_id'], $row['driver_id'],
      $u['id'], $rating, $comment ?: null
    ]);

    $flash = 'บันทึกการประเมินเรียบร้อย ขอบคุณสำหรับความคิดเห็นของคุณ';
    // reload eligible list
    $q->execute([$u['id'], $u['id']]);
    $eligible = $q->fetchAll();

  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

/* --- รายการรีวิวที่ผ่านมา (ของผู้ใช้คนนี้) --- */
$mine = $pdo->prepare("
SELECT r.id, r.created_at, r.rating, r.comment,
       b.id as booking_id, b.start_datetime, b.end_datetime,
       v.plate_no, v.brand_model,
       u2.name as driver_name
FROM driver_reviews r
JOIN bookings b ON b.id = r.booking_id
JOIN vehicles v ON v.id = b.vehicle_id
LEFT JOIN users u2 ON u2.id = r.driver_id
WHERE r.rater_user_id = ?
ORDER BY r.id DESC
LIMIT 50
");
$mine->execute([$u['id']]);
$myReviews = $mine->fetchAll();

render_header('ประเมินคนขับรถ • Fleet', '<style>
/* ปรับหน้าตาฟอร์มเล็กน้อยให้เป็นมิตรกับมือถือ */
.form-grid { display: grid; gap: 10px; }
.rating-group { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.rating-pill {
  display:inline-flex; align-items:center; justify-content:center;
  width:36px; height:36px; border-radius: 999px;
  border:1px solid #cfd7e3; cursor:pointer; user-select:none;
  font-weight:600; color:#0A3F9C; background:#fff;
}
.rating-input { position: absolute; opacity: 0; pointer-events:none; }
.rating-input:checked + .rating-pill {
  background:#0B5ED7; color:#fff; border-color:#0B5ED7;
}
.actions { display:flex; gap:10px; justify-content:flex-start; margin-top: 4px;}
@media (max-width:640px){ .actions { flex-direction: column; } }
</style>');
?>
  <?php if($flash): ?><div class="flash"><?=h($flash)?></div><?php endif; ?>
  <?php if($error): ?><div class="flash" style="background:#ffecec;border-color:#ffcccc;color:#a33;"><?=h($error)?></div><?php endif; ?>

  <div class="card">
    <h2>ประเมินคนขับรถ (เฉพาะการจองรถใช้ภายนอก)</h2>
    <p style="margin-top:-6px;color:#444">เลือกการจองที่เสร็จสิ้นแล้ว จากนั้นให้คะแนน 1–5 และแสดงความคิดเห็น (ถ้ามี)</p>

    <?php if(empty($eligible)): ?>
      <div class="flash">ยังไม่มีการจองที่พร้อมสำหรับการประเมิน (หรือคุณได้ประเมินครบแล้ว)</div>
    <?php else: ?>
    <form method="post" autocomplete="off" class="form-grid">
      <?= form_brand_badge(); ?>
      <input type="hidden" name="csrf_token" value="<?=$csrf?>">

      <label>เลือกการจอง</label>
      <select name="booking_id" required>
        <option value="">-- เลือก --</option>
        <?php foreach($eligible as $e): 
          $label = sprintf(
            '#%d • %s • %s → %s • คนขับ: %s',
            $e['booking_id'],
            $e['plate_no'].' • '.$e['brand_model'],
            date('d/m H:i', strtotime($e['start_datetime'])),
            date('d/m H:i', strtotime($e['end_datetime'])),
            $e['driver_name'] ?: '-'
          );
        ?>
          <option value="<?=$e['booking_id']?>"><?=h($label)?></option>
        <?php endforeach; ?>
      </select>

      <label>ให้คะแนน</label>
      <div class="rating-group" role="radiogroup" aria-label="ให้คะแนน">
        <?php for($i=5;$i>=1;$i--): // แสดง 5 ไป 1 (ทั่วไปคุ้นเคยกับ 5 อยู่ซ้าย) ?>
          <label>
            <input class="rating-input" type="radio" name="rating" value="<?=$i?>" required>
            <span class="rating-pill"><?=$i?></span>
          </label>
        <?php endfor; ?>
      </div>

      <label>ความคิดเห็นเพิ่มเติม (ไม่บังคับ)</label>
      <textarea name="comment" rows="3" placeholder="เช่น ตรงต่อเวลา สุภาพ ขับขี่ปลอดภัย ฯลฯ"></textarea>

      <div class="actions">
        <button>บันทึกการประเมิน</button>
        <a class="btn secondary" href="dashboard.php">กลับ</a>
      </div>
    </form>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>การประเมินของฉันล่าสุด</h2>
    <?php if(empty($myReviews)): ?>
      <div class="flash">ยังไม่มีการประเมิน</div>
    <?php else: ?>
      <table>
        <tr>
          <th>#</th><th>การจอง</th><th>รถ</th><th>คนขับ</th><th>คะแนน</th><th>ความคิดเห็น</th><th>เวลา</th>
        </tr>
        <?php foreach($myReviews as $r): ?>
          <tr>
            <td><?=h($r['id'])?></td>
            <td>#<?=h($r['booking_id'])?><br><small><?=h($r['start_datetime'].' → '.$r['end_datetime'])?></small></td>
            <td><?=h($r['plate_no'].' • '.$r['brand_model'])?></td>
            <td><?=h($r['driver_name'] ?: '-')?></td>
            <td><?=h($r['rating'])?></td>
            <td><?=h($r['comment'] ?: '-')?></td>
            <td><?=h(date('d/m/Y H:i', strtotime($r['created_at'])))?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

<?php render_footer(); ?>
