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

// helper: fetch trip with joins and permission check
function load_trip($pdo,$id,$u,$is_manager){
  $stmt = $pdo->prepare("SELECT t.*, 
                                b.user_id as booking_user_id, 
                                v.plate_no, v.brand_model, v.fuel_rate_per_km, 
                                v.is_campus_only
                         FROM trips t
                         JOIN bookings b ON b.id=t.booking_id
                         JOIN vehicles v ON v.id=t.vehicle_id
                         WHERE t.id=?");
  $stmt->execute([$id]);
  $t = $stmt->fetch();
  if(!$t) return [null, 'ไม่พบทริป'];
  if(!$is_manager && !in_array($u['role'], ['ADMIN'])){
    if ($t['driver_id'] != $u['id'] && $t['booking_user_id'] != $u['id']) {
      return [null, 'คุณไม่มีสิทธิ์จัดการทริปนี้'];
    }
  }
  if($t['actual_end'] !== null){
    return [null, 'ทริปนี้สิ้นสุดแล้ว'];
  }
  return [$t, null];
}

$flash = $flash ?? '';
$error = $error ?? null;

// ending post
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['trip_id'])){
  $trip_id = (int)$_POST['trip_id'];
  list($t, $err) = load_trip($pdo,$trip_id,$u,$is_manager);
  if(!$t){ 
    $error = $err; 
  } else {
    $isInternal = !empty($t['is_campus_only']); // รถภายใน?
    // input จากฟอร์ม (อาจถูกเพิกเฉยถ้าเป็นรถภายใน)
    $end_odo_input = ($_POST['end_odometer']!=='') ? (int)$_POST['end_odometer'] : null;

    // ✅ ถ้าเป็นรถภายใน: บังคับ end_odo = start_odo + 1 (ถ้ามี start_odo)
    if ($isInternal && $t['start_odometer'] !== null) {
      $end_odo = (int)$t['start_odometer'] + 1;
    } else {
      $end_odo = $end_odo_input;
    }

    // Validation (เฉพาะกรณีที่ยังต้องเช็ค)
    if ($t['distance_source']==='ODOMETER') {
      if (!$isInternal) { // ภายในไม่ต้องกรอก/ตรวจ เพราะถูกกำหนดอัตโนมัติแล้ว
        if ($end_odo === null) {
          $error = 'กรุณากรอกเลขไมล์สิ้นสุด';
        } elseif ($t['start_odometer'] !== null && $end_odo < (int)$t['start_odometer']) {
          $error = 'เลขไมล์สิ้นสุดต้องไม่น้อยกว่าเลขไมล์เริ่มต้น';
        }
      }
    } else { // GPS mode
      if ($end_odo !== null && $t['start_odometer'] !== null && $end_odo < (int)$t['start_odometer']) {
        $error = 'เลขไมล์สิ้นสุดต้องไม่น้อยกว่าเลขไมล์เริ่มต้น';
      }
    }

    if (!$error) {
      // คำนวณระยะทางและค่าน้ำมัน
      $total_km = null;
      if ($t['distance_source']==='ODOMETER' && $t['start_odometer']!==null && $end_odo!==null) {
        // ถ้าเป็นรถภายในและมี start_odo => จะได้ 1 เสมอ
        $total_km = max(0, (int)$end_odo - (int)$t['start_odometer']);
      }
      $fuel_cost = null;
      if($total_km!==null && $t['fuel_rate_per_km']!==null){
        $fuel_cost = $total_km * (float)$t['fuel_rate_per_km'];
      }

      try {
        $pdo->beginTransaction();

        $upd = $pdo->prepare("UPDATE trips 
                              SET end_odometer=?, total_km=?, fuel_cost=?, actual_end=NOW() 
                              WHERE id=?");
        $upd->execute([$end_odo, $total_km, $fuel_cost, $trip_id]);

        // อัปเดตเลขไมล์รถ (ถ้ามี end_odo)
        if ($end_odo !== null) {
          $uveh = $pdo->prepare("UPDATE vehicles 
                                 SET current_odometer = GREATEST(COALESCE(current_odometer,0), ?) 
                                 WHERE id=?");
          $uveh->execute([$end_odo, $t['vehicle_id']]);
        }

        $pdo->commit();
        $flash = 'สิ้นสุดทริปเรียบร้อยแล้ว';
      } catch (Throwable $tx) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = 'บันทึกไม่สำเร็จ: '.$tx->getMessage();
      }
    }
  }
}

// resolve which trip to show
$trip_id = isset($_GET['trip_id']) ? (int)$_GET['trip_id'] : 0;
$trip = null;
if($trip_id){
  list($trip, $err) = load_trip($pdo,$trip_id,$u,$is_manager);
  if(!$trip) $error = $err;
} else {
  // load active trips for this user
  if($is_manager){
    $q = $pdo->query("SELECT t.id, v.plate_no, v.brand_model, t.actual_start
                      FROM trips t JOIN vehicles v ON v.id=t.vehicle_id
                      WHERE t.actual_end IS NULL ORDER BY t.actual_start DESC");
  } else {
    $stmt = $pdo->prepare("SELECT t.id, v.plate_no, v.brand_model, t.actual_start
                           FROM trips t
                           JOIN bookings b ON b.id=t.booking_id
                           JOIN vehicles v ON v.id=t.vehicle_id
                           WHERE t.actual_end IS NULL AND (t.driver_id=? OR b.user_id=?) 
                           ORDER BY t.actual_start DESC");
    $stmt->execute([$u['id'],$u['id']]); $q = $stmt;
  }
  $active = $q->fetchAll();
  if(count($active)===1){
    $trip_id = (int)$active[0]['id'];
    list($trip, $err) = load_trip($pdo,$trip_id,$u,$is_manager);
    if(!$trip) $error = $err;
  } elseif(count($active)===0){
    $no_active = true;
  } else {
    $choices = $active;
  }
}

?>
<?php render_header('สิ้นสุดทริป • Fleet'); ?>
  <?php if(!empty($flash)): ?><div class="flash"><?=h($flash)?></div><?php endif; ?>
  <?php if(!empty($error)): ?><div class="flash" style="background:#ffecec;border-color:#ffcccc;color:#a33;"><?=h($error)?></div><?php endif; ?>

  <?php if(!empty($no_active)): ?>
  <div class="card"><h2>ไม่มีทริปที่กำลังใช้งาน</h2>
    <p>คุณยังไม่มีทริปที่เริ่มไว้ หรือทริปทั้งหมดสิ้นสุดแล้ว</p>
    <p><a class="btn" href="trips_start.php">เริ่มทริป</a></p>
  </div>
  <?php elseif(!empty($choices)): ?>
  <div class="card"><h2>เลือกรายการทริปที่ต้องการสิ้นสุด</h2>
    <form method="get">
      <select name="trip_id" required>
        <option value="">-- เลือก --</option>
        <?php foreach($choices as $c): ?>
          <option value="<?=$c['id']?>">#<?=$c['id']?> • <?=h($c['plate_no'].' • '.$c['brand_model'])?> • เริ่ม <?=h(date('d/m H:i', strtotime($c['actual_start'])))?></option>
        <?php endforeach; ?>
      </select>
      <button>เปิดทริป</button>
    </form>
  </div>
  <?php elseif($trip): ?>
  <?php
    $isInternalTrip = !empty($trip['is_campus_only']);
    $autoEndOdo = ($isInternalTrip && $trip['start_odometer'] !== null) ? ((int)$trip['start_odometer'] + 1) : null;
  ?>
  <div class="card">
    <h2>สิ้นสุดทริป #<?=h($trip['id'])?> — <?=h($trip['plate_no'].' • '.$trip['brand_model'])?></h2>
    <form method="post">
      <?php echo form_brand_badge(); ?>
      <input type="hidden" name="trip_id" value="<?=$trip['id']?>">

      <?php if($isInternalTrip): ?>
        <!-- ✅ รถภายใน: เลขไมล์สิ้นสุด = เริ่ม + 1, ห้ามแก้ -->
        <label>เลขไมล์สิ้นสุด</label>
        <input type="number" name="end_odometer"
               value="<?= h($autoEndOdo ?? '') ?>"
               readonly
               <?= $autoEndOdo !== null ? '' : 'placeholder="ไม่ทราบเลขไมล์เริ่ม — ระบบจะไม่บันทึกเลขไมล์สิ้นสุด"' ?> >
        <div style="color:#666;margin-top:4px">
          <small>รถใช้ภายในมหาวิทยาลัย — ระบบกำหนดเลขไมล์สิ้นสุดอัตโนมัติเป็น +1 จากเลขไมล์เริ่มต้น</small>
        </div>
      <?php else: ?>
        <?php if($trip['distance_source']==='ODOMETER'): ?>
          <label>เลขไมล์สิ้นสุด</label>
          <input type="number" name="end_odometer" min="<?= (int)$trip['start_odometer'] ?>" required>
        <?php else: ?>
          <label>เลขไมล์สิ้นสุด (ไม่บังคับ)</label>
          <input type="number" name="end_odometer" min="<?= (int)($trip['start_odometer'] ?? 0) ?>" placeholder="ถ้าต้องการบันทึกร่วมกับ GPS">
        <?php endif; ?>
      <?php endif; ?>

      <button>บันทึกการสิ้นสุดทริป</button>
      <a class="btn secondary" href="dashboard.php">กลับ</a>
    </form>
    <div style="margin-top:10px;color:#555">
      <div>เริ่ม: <?=h($trip['actual_start'])?><?= $trip['start_odometer']!==null ? ' • เลขไมล์เริ่ม: '.h($trip['start_odometer']) : '' ?></div>
      <div>วิธีนับระยะทาง: <?=h($trip['distance_source'])?></div>
      <?php if($trip['fuel_rate_per_km']!==null): ?><div>อัตราค่าน้ำมัน: <?=h(number_format($trip['fuel_rate_per_km'],2))?> ฿/กม.</div><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

<?php render_footer(); ?>
