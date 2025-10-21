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

$u   = require_role(['ADMIN','APPROVER_L1','APPROVER_L2','DRIVER']);
$pdo = db();

$vehicles = $pdo->query("SELECT id, plate_no, brand_model, current_odometer FROM vehicles WHERE active=1 ORDER BY plate_no ASC")->fetchAll();
$drivers  = $pdo->query("SELECT id, name FROM users ORDER BY name ASC")->fetchAll();

$flash=''; $error='';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  try {
    $vehicle_id     = (int)($_POST['vehicle_id'] ?? 0);
    $driver_id      = $_POST['driver_id']!=='' ? (int)$_POST['driver_id'] : $u['id'];
    $inspect_date   = $_POST['inspect_date'] ?? date('Y-m-d');
    $start_odo      = $_POST['start_odometer']!=='' ? (int)$_POST['start_odometer'] : null;

    // 15 รายการ (ไม่รวม "อื่น ๆ")
    $f = fn($k)=> isset($_POST[$k]) ? 1 : 0;
    $fuel_ok        = $f('fuel_ok');
    $engine_oil_ok  = $f('engine_oil_ok');
    $radiator_ok    = $f('radiator_ok');
    $battery_ok     = $f('battery_ok');
    $battery_term_ok= $f('battery_term_ok');
    $belt_ok        = $f('belt_ok');
    $brake_ok       = $f('brake_ok');
    $steering_ok    = $f('steering_ok');
    $lights_ok      = $f('lights_ok');
    $horn_ok        = $f('horn_ok');
    $wiper_ok       = $f('wiper_ok');
    $tires_ok       = $f('tires_ok');
    $spare_ok       = $f('spare_ok');
    $tools_ok       = $f('tools_ok');
    $clean_ok       = $f('clean_ok');

    // “อื่น ๆ” ใช้เป็นช่องข้อความ และ map other_ok อัตโนมัติ
    $other_text     = trim($_POST['other_text'] ?? '');
    $other_ok       = ($other_text === '') ? 1 : 0;

    $defects_text   = trim($_POST['defects_text'] ?? '');
    $notes          = trim($_POST['notes'] ?? '');

    if (!$vehicle_id)   throw new Exception('กรุณาเลือกรถ');
    if (!$inspect_date) throw new Exception('กรุณาเลือกวันที่ตรวจ');

    $ins = $pdo->prepare("
      INSERT INTO vehicle_inspections
      (vehicle_id, driver_id, inspect_date, start_odometer,
       fuel_ok, engine_oil_ok, radiator_ok, battery_ok, battery_term_ok, belt_ok,
       brake_ok, steering_ok, lights_ok, horn_ok, wiper_ok, tires_ok, spare_ok,
       tools_ok, clean_ok, other_ok, defects_text, other_text, notes, created_at, updated_at)
      VALUES
      (:vehicle_id, :driver_id, :inspect_date, :start_odometer,
       :fuel_ok, :engine_oil_ok, :radiator_ok, :battery_ok, :battery_term_ok, :belt_ok,
       :brake_ok, :steering_ok, :lights_ok, :horn_ok, :wiper_ok, :tires_ok, :spare_ok,
       :tools_ok, :clean_ok, :other_ok, :defects_text, :other_text, :notes, NOW(), NOW())
    ");
    $ins->execute([
      ':vehicle_id'=>$vehicle_id, ':driver_id'=>$driver_id, ':inspect_date'=>$inspect_date, ':start_odometer'=>$start_odo,
      ':fuel_ok'=>$fuel_ok, ':engine_oil_ok'=>$engine_oil_ok, ':radiator_ok'=>$radiator_ok, ':battery_ok'=>$battery_ok, ':battery_term_ok'=>$battery_term_ok, ':belt_ok'=>$belt_ok,
      ':brake_ok'=>$brake_ok, ':steering_ok'=>$steering_ok, ':lights_ok'=>$lights_ok, ':horn_ok'=>$horn_ok, ':wiper_ok'=>$wiper_ok, ':tires_ok'=>$tires_ok, ':spare_ok'=>$spare_ok,
      ':tools_ok'=>$tools_ok, ':clean_ok'=>$clean_ok, ':other_ok'=>$other_ok, ':defects_text'=>$defects_text, ':other_text'=>$other_text, ':notes'=>$notes
    ]);

    if ($start_odo!==null) {
      $pdo->prepare("UPDATE vehicles SET current_odometer = GREATEST(IFNULL(current_odometer,0), ?) WHERE id=?")
          ->execute([$start_odo, $vehicle_id]);
    }

    redirect('vehicle_inspections.php?ok=1');
  } catch(Throwable $e) { $error = $e->getMessage(); }
}

render_header('บันทึกตรวจสภาพรถ • Fleet');
?>
<?php if(isset($_GET['ok'])): ?><div class="flash">บันทึกสำเร็จ</div><?php endif; ?>
<?php if($error): ?><div class="flash" style="background:#ffecec;border-color:#ffcccc;color:#a33;"><?=h($error)?></div><?php endif; ?>

<div class="card">
  <h2>แบบรายการตรวจสภาพรถก่อนใช้งานรายวัน (FM-อาคาร-02-06)</h2>

  <form method="post" autocomplete="off">
    <?php echo function_exists('form_brand_badge') ? form_brand_badge() : ''; ?>

    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
      <div>
        <label>รถ *</label>
        <select name="vehicle_id" required id="vehicle_id">
          <option value="">— เลือกรถ —</option>
          <?php foreach($vehicles as $v): ?>
            <option value="<?=$v['id']?>" data-odo="<?= (int)$v['current_odometer'] ?>">
              <?=h($v['plate_no'].' • '.$v['brand_model'])?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label>พนักงานขับ/ผู้ตรวจ</label>
        <div style="display:flex; gap:8px; align-items:center;">
          <select name="driver_id" id="driver_id">
            <option value="">— เลือก (ว่าง = ผู้ใช้ระบบ) —</option>
            <?php foreach($drivers as $d): ?>
              <option value="<?=$d['id']?>" <?= $d['id']==$u['id']?'selected':'' ?>><?=h($d['name'])?></option>
            <?php endforeach; ?>
          </select>
 
        </div>
      </div>

      <div>
        <label>วันที่ตรวจ *</label>
        <input type="date" name="inspect_date" value="<?=h(date('Y-m-d'))?>" required>
      </div>

      <div>
        <label>เริ่มใช้รถเมื่อเลขกิโลเมตรที่</label>
        <input type="number" name="start_odometer" id="start_odometer" placeholder="">
      </div>
    </div>

    <hr style="margin:12px 0">

    <?php
      // 15 รายการแรก (ย้าย "อื่น ๆ" ไปเป็นช่องข้อความข้างล่าง)
      $items = [
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
      ];
    ?>

    <!-- ตารางรายการตรวจ (15 รายการ) -->
    <table style="width:100%; border-collapse:collapse;">
      <thead>
        <tr>
          <th style="border:1px solid #999; padding:6px 8px; width:60px; text-align:center;">ลำดับ</th>
          <th style="border:1px solid #999; padding:6px 8px;">รายการตรวจสอบ</th>
          <th style="border:1px solid #999; padding:6px 8px; width:140px; text-align:center;">ผ่าน (✓)</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($items as $row): [$name,$label] = $row; ?>
        <tr>
          <td style="border:1px solid #ccc; padding:6px 8px; text-align:center;"><?=preg_replace('/[^0-9]/','',$label)?></td>
          <td style="border:1px solid #ccc; padding:6px 8px;"><?=h($label)?></td>
          <td style="border:1px solid #ccc; padding:6px 8px; text-align:center;">
            <input type="checkbox" name="<?=h($name)?>" value="1" checked>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div style="margin-top:12px;">
      <label>16. อื่น ๆ (รายละเอียด)</label>
      <input type="text" name="other_text" placeholder="ระบุถ้ามี" style="width:100%;">
      <div class="small" style="color:#666; margin-top:4px;">
        * ถ้าเว้นว่าง ระบบถือว่า “ผ่าน” (other_ok=1) แต่ถ้ากรอกข้อความ ระบบจะบันทึก other_ok=0 อัตโนมัติ
      </div>
    </div>

    <div style="margin-top:10px;">
      <label>สภาพบกพร่อง</label>
      <textarea name="defects_text" rows="3" placeholder="รายการบกพร่อง/สิ่งที่ต้องแก้ไข" style="width:100%;"></textarea>
    </div>

    <div style="margin-top:10px;">
      <label>บันทึกเพิ่มเติม</label>
      <textarea name="notes" rows="2" style="width:100%;"></textarea>
    </div>

    <div style="margin-top:12px;">
      <button type="submit">บันทึก</button>
      <a class="btn secondary" href="vehicle_inspections.php">กลับ</a>
    </div>
  </form>
</div>

<script>
const selVeh = document.getElementById('vehicle_id');
const odo    = document.getElementById('start_odometer');
selVeh.addEventListener('change', ()=>{
  const opt = selVeh.selectedOptions[0];
  const vodo = opt ? opt.getAttribute('data-odo') : '';
  odo.placeholder = vodo ? ('เลขไมล์ปัจจุบัน: '+vodo) : '';
});

// ลิงก์โปรไฟล์ผู้ตรวจจาก user (ปรับ path ได้ที่ baseUrl)
const driverSel  = document.getElementById('driver_id');
const driverLink = document.getElementById('driver_link');
const baseUrlForUser = 'user.php?id='; // <-- ถ้าระบบคุณใช้ไฟล์อื่น เปลี่ยนตรงนี้ เช่น 'users_view.php?id='

function syncDriverLink(){
  const id = driverSel.value;
  if (id) {
    driverLink.href = baseUrlForUser + encodeURIComponent(id);
    driverLink.style.display = '';
  } else {
    driverLink.style.display = 'none';
  }
}
driverSel.addEventListener('change', syncDriverLink);
syncDriverLink();
</script>

<?php render_footer(); ?>
