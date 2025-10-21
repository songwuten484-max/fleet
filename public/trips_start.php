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
require_once __DIR__.'/../inc/helpers.php';
require_once __DIR__.'/../inc/db.php';

$u = require_role(['ADMIN','APPROVER_L1','APPROVER_L2','DRIVER','USER']);
$pdo = db();

$is_manager = in_array($u['role'], ['ADMIN','APPROVER_L1','APPROVER_L2']);
$is_driver  = ($u['role'] === 'DRIVER');

$flash = ''; $error = '';

/* ★ เช็กทริปค้างของผู้ใช้คนนี้ (ยังไม่สิ้นสุด) */
$activeClause = " (actual_end IS NULL OR actual_end='0000-00-00 00:00:00') ";
$stmt = $pdo->prepare("SELECT t.id, v.plate_no, v.brand_model, t.actual_start
                       FROM trips t
                       JOIN vehicles v ON v.id=t.vehicle_id
                       WHERE t.driver_id=? AND $activeClause
                       ORDER BY t.actual_start DESC
                       LIMIT 1");
$stmt->execute([$u['id']]);
$myUnfinished = $stmt->fetch();

/* ===== โหลดรายการจองที่ "ยังเริ่มทริปได้" =====
   เงื่อนไข:
   - อนุมัติแล้ว (APPROVED หรือ APPROVED_L1)
   - ยังไม่หมดเวลา (end_datetime >= NOW())
   - ยังไม่เคยมีทริปของ booking นี้ (NOT EXISTS trips.booking_id = b.id)
   - เพิ่ม: current_odometer และ destination ของการจอง
*/
$params = [];
$sql = "SELECT b.id, b.user_id, b.vehicle_id, b.start_datetime, b.end_datetime,
               b.destination AS dest,
               v.plate_no, v.brand_model,
               v.current_odometer AS veh_odo
        FROM bookings b
        JOIN vehicles v ON v.id = b.vehicle_id
        WHERE b.status IN ('APPROVED','APPROVED_L1')
          AND b.end_datetime >= NOW()
          AND NOT EXISTS (SELECT 1 FROM trips t WHERE t.booking_id = b.id)";
if (!$is_manager && !$is_driver) {
  // USER: เห็นเฉพาะของตัวเอง และ (จาก requirement ก่อนหน้า) รถภายในมหาวิทยาลัยเท่านั้น
  $sql .= " AND b.user_id = ? AND v.is_campus_only = 1";
  $params[] = $u['id'];
}
$sql .= " ORDER BY b.start_datetime ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll();

/* ===== กดเริ่มทริป ===== */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  // ★ กันไว้ที่เซิร์ฟเวอร์อีกชั้น: ถ้ามีทริปค้างของตัวเองอยู่ ห้ามเริ่มใหม่
  if ($myUnfinished) {
    $error = 'คุณมีทริปที่ยังไม่สิ้นสุด (#'.$myUnfinished['id'].') กรุณาปิดทริปก่อนเริ่มใหม่';
  }

  if (!$error) {
    $booking_id = (int)($_POST['booking_id'] ?? 0);
    $distance_source = $_POST['distance_source'] ?? 'ODOMETER';

    try {
      // โหลดคำขออีกครั้ง พร้อมตรวจสิทธิ์ และดึงข้อมูลที่ต้องใช้
      $params2 = [$booking_id];
      $condUser = '';
      if (!$is_manager && !$is_driver) {
        // USER: ต้องเป็นผู้จอง และเป็นรถภายใน
        $condUser = " AND b.user_id=? AND v.is_campus_only=1";
        $params2[] = $u['id'];
      }

      $chkSql = "SELECT b.*, b.destination AS dest,
                        v.plate_no, v.brand_model,
                        v.current_odometer AS veh_odo,
                        v.id AS veh_id
                 FROM bookings b
                 JOIN vehicles v ON v.id=b.vehicle_id
                 WHERE b.id=?
                   AND b.status IN ('APPROVED','APPROVED_L1')
                   AND b.end_datetime >= NOW()
                   AND NOT EXISTS (SELECT 1 FROM trips t WHERE t.booking_id = b.id)"
               . $condUser . " LIMIT 1";

      $stmt = $pdo->prepare($chkSql);
      $stmt->execute($params2);
      $b = $stmt->fetch();

      if (!$b) throw new Exception('คำขอใช้รถนี้ไม่พร้อมสำหรับการเริ่มทริป (หมดเวลา/ถูกใช้ไปแล้ว/ไม่มีสิทธิ์)');

      // กันซ้ำระดับรถ
      $q = $pdo->prepare("SELECT id FROM trips WHERE vehicle_id=? AND $activeClause LIMIT 1");
      $q->execute([$b['vehicle_id']]);
      if ($q->fetch()) throw new Exception('มีทริปที่ยังไม่สิ้นสุดของรถคันนี้อยู่แล้ว');

      // ★ ล็อกเลขไมล์เริ่มต้นจากฐานข้อมูล
      $start_odo = null;
      if ($distance_source === 'ODOMETER') {
        $start_odo = isset($b['veh_odo']) ? (int)$b['veh_odo'] : null;
      }

      // บันทึกทริป (driver คือคนที่กดเริ่ม)
      $ins = $pdo->prepare("INSERT INTO trips
          (booking_id, vehicle_id, driver_id, start_odometer, actual_start, distance_source)
          VALUES (?,?,?,?,NOW(),?)");
      $ins->execute([$booking_id, $b['vehicle_id'], $u['id'], $start_odo, $distance_source]);
      $trip_id = $pdo->lastInsertId();

      redirect('trips_end.php?trip_id='.$trip_id);
    } catch (Throwable $e) {
      $error = $e->getMessage();
    }
  }
}

/* ===== ทริปที่กำลังใช้งาน (ยังไม่สิ้นสุด) — สำหรับแสดงรายการของผู้ใช้/ผู้จัดการ ===== */
if ($is_manager) {
  $q = $pdo->query("SELECT t.id, v.plate_no, v.brand_model, t.actual_start
                    FROM trips t
                    JOIN vehicles v ON v.id=t.vehicle_id
                    WHERE $activeClause
                    ORDER BY t.actual_start DESC");
  $myActive = $q->fetchAll();
} else {
  $stmt = $pdo->prepare("SELECT t.id, v.plate_no, v.brand_model, t.actual_start
                         FROM trips t
                         JOIN bookings b ON b.id=t.booking_id
                         JOIN vehicles v ON v.id=t.vehicle_id
                         WHERE $activeClause
                           AND (t.driver_id=? OR b.user_id=?)
                         ORDER BY t.actual_start DESC");
  $stmt->execute([$u['id'],$u['id']]);
  $myActive = $stmt->fetchAll();
}

?>
<?php render_header('เริ่มทริป • Fleet'); ?>
  <?php if($flash): ?><div class="flash"><?=h($flash)?></div><?php endif; ?>
  <?php if($error): ?><div class="flash" style="background:#ffecec;border-color:#ffcccc;color:#a33;"><?=h($error)?></div><?php endif; ?>

  <?php if($myUnfinished): ?>
    <!-- ★ การ์ดแจ้งว่ามีทริปค้าง -->
    <div class="flash" style="background:#fff8e6;border-color:#ffe08a;color:#8a6d3b;margin-bottom:12px">
      คุณมีทริปที่ยังไม่สิ้นสุดอยู่: 
      #<?=h($myUnfinished['id'])?> • <?=h($myUnfinished['plate_no'].' • '.$myUnfinished['brand_model'])?> • เริ่ม <?=h(date('d/m/Y H:i', strtotime($myUnfinished['actual_start'])))?><br>
      กรุณา <a href="trips_end.php?trip_id=<?=h($myUnfinished['id'])?>" class="btn">ไปหน้าสิ้นสุดทริป</a> ก่อนเริ่มทริปใหม่
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>เริ่มต้นการใช้รถ</h2>
    <form method="post" id="form-start" autocomplete="off">
      <?php echo function_exists('form_brand_badge') ? form_brand_badge() : ''; ?>

      <label>การจอง</label>
      <select name="booking_id" id="booking_id" required <?= $myUnfinished ? 'disabled' : '' ?>>
        <option value="">-- เลือก --</option>
        <?php foreach($bookings as $r):
          $label = sprintf("#%d • %s • %s → %s",
              $r['id'],
              $r['plate_no'].' • '.$r['brand_model'],
              date('d/m H:i', strtotime($r['start_datetime'])),
              date('d/m H:i', strtotime($r['end_datetime']))
          );
          $vehOdo = isset($r['veh_odo']) ? (int)$r['veh_odo'] : '';
          $dest   = isset($r['dest']) ? $r['dest'] : '';
        ?>
          <option value="<?=$r['id']?>"
                  data-vehicle="<?=$r['vehicle_id']?>"
                  data-plate="<?=h($r['plate_no'].' • '.$r['brand_model'])?>"
                  data-odo="<?=$vehOdo?>"
                  data-dest="<?=h($dest)?>">
            <?=h($label)?>
          </option>
        <?php endforeach; ?>
      </select>

      <label>รถที่ใช้</label>
      <input id="vehicle_display" value="" readonly placeholder="เลือกการจองก่อน" />
      <input type="hidden" name="vehicle_id" id="vehicle_id" value="">

      <label>เลขไมล์เริ่มต้น</label>
      <input name="start_odometer" id="start_odometer" type="number" min="0" readonly>

      <label>ปลายทาง</label>
      <input id="destination_display" value="" readonly placeholder="เลือกการจองก่อน">

      <label>วิธีนับระยะทาง</label>
      <select name="distance_source" id="distance_source" <?= $myUnfinished ? 'disabled' : '' ?>>
        <option value="ODOMETER">ใช้เลขไมล์</option>
        <option value="GPS">ใช้ GPS</option>
      </select>

      <button <?= $myUnfinished ? 'disabled title="คุณมีทริปค้างอยู่ กรุณาสิ้นสุดก่อน"' : '' ?>>เริ่มเดินทาง</button>
      <a class="btn secondary" href="dashboard.php">กลับ</a>
    </form>
  </div>

  <?php if(!empty($myActive)): ?>
    <div class="card">
      <h2>รายการทริปที่กำลังใช้งานของฉัน</h2>
      <table>
        <tr><th>#</th><th>รถ</th><th>เริ่มเมื่อ</th><th>จัดการ</th></tr>
        <?php foreach($myActive as $c): ?>
        <tr>
          <td><?=h($c['id'])?></td>
          <td><?=h($c['plate_no'].' • '.$c['brand_model'])?></td>
          <td><?=h(date('d/m/Y H:i', strtotime($c['actual_start'])))?></td>
          <td><a class="btn" href="trips_end.php?trip_id=<?=$c['id']?>">ไปหน้าสิ้นสุดทริป</a></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endif; ?>

<script>
const sel   = document.getElementById('booking_id');
const vdisp = document.getElementById('vehicle_display');
const vid   = document.getElementById('vehicle_id');
const odo   = document.getElementById('start_odometer');
const dest  = document.getElementById('destination_display');

if (vdisp) vdisp.readOnly = true;
if (odo)   odo.readOnly   = true;
if (dest)  dest.readOnly  = true;

if (sel) sel.addEventListener('change', ()=>{
  const opt = sel.options[sel.selectedIndex];
  const v = opt ? opt.getAttribute('data-vehicle') : '';
  const p = opt ? opt.getAttribute('data-plate')   : '';
  const o = opt ? opt.getAttribute('data-odo')     : '';
  const d = opt ? opt.getAttribute('data-dest')    : '';

  vid.value   = v || '';
  vdisp.value = p || '';
  odo.value = o || '';
  odo.placeholder = o ? ('เลขไมล์ปัจจุบัน: ' + o) : '';
  dest.value = d || '';
});
</script>

<?php render_footer(); ?>
