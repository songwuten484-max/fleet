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
$u = $_SESSION['user'];



require_once __DIR__.'/../inc/config.php';
require_once __DIR__.'/../inc/layout.php';
require_once __DIR__.'/../inc/helpers.php';
require_once __DIR__.'/../inc/db.php';

$pdo = db();


//$u['role'] = $user['role'] ?? 'user';



$is_manager = in_array($u['role'], ['ADMIN','APPROVER_L1','APPROVER_L2']);

// ==== Stats ====
$total_vehicles = (int)$pdo->query("SELECT COUNT(*) c FROM vehicles")->fetch()['c'];
$pending = (int)$pdo->query("SELECT COUNT(*) c FROM bookings WHERE status='PENDING'")->fetch()['c'];
$upcoming = (int)$pdo->query("SELECT COUNT(*) c FROM bookings WHERE status IN ('APPROVED','APPROVED_L1') AND start_datetime >= NOW() AND start_datetime < DATE_ADD(NOW(), INTERVAL 7 DAY)")->fetch()['c'];
$active_trips = (int)$pdo->query("SELECT COUNT(*) c FROM trips WHERE actual_end IS NULL")->fetch()['c'];
$month_total = date('Y-m-01');
$sum = $pdo->prepare("SELECT COALESCE(SUM(total_km),0) as km, COALESCE(SUM(fuel_cost),0) as cost FROM trips WHERE actual_start >= ? AND actual_start < DATE_ADD(?, INTERVAL 1 MONTH)");
$sum->execute([$month_total,$month_total]); $s = $sum->fetch();

// ==== Calendar params ====
$ym = $_GET['month'] ?? null; // fallback legacy param (YYYY-MM)
$year_sel = isset($_GET['year']) ? (int)$_GET['year'] : null;
$mon_sel  = isset($_GET['m']) ? (int)$_GET['m'] : null;
if ($year_sel && $mon_sel>=1 && $mon_sel<=12) {
  $month = sprintf('%04d-%02d', $year_sel, $mon_sel);
} elseif ($ym && preg_match('/^\d{4}-\d{2}$/', $ym)) {
  $month = $ym;
} else {
  $month = date('Y-m');
}
$vehicle_id = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0;
$start = $month.'-01';
$end = date('Y-m-d', strtotime("$start +1 month"));
$vehicles = $pdo->query("SELECT id, plate_no, brand_model FROM vehicles WHERE active=1 ORDER BY plate_no")->fetchAll();

// ==== Fetch bookings overlapping this month (pending/approved) ====
$sql = "SELECT b.id,b.user_id,b.vehicle_id,b.start_datetime,b.end_datetime,b.status,u.name as uname,v.plate_no
        FROM bookings b
        JOIN users u ON u.id=b.user_id
        JOIN vehicles v ON v.id=b.vehicle_id
        WHERE b.status IN ('PENDING','APPROVED_L1','APPROVED')
          AND b.start_datetime < ? AND b.end_datetime >= ?";
$params = [$end, $start];
if ($vehicle_id) { $sql .= " AND b.vehicle_id=?"; $params[] = $vehicle_id; }
$sql .= " ORDER BY b.start_datetime ASC";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$rows = $stmt->fetchAll();

// ==== Bucket bookings by day ====
$byDay = [];
foreach($rows as $r){
  $st = max(strtotime($r['start_datetime']), strtotime($start.' 00:00:00'));
  $en = min(strtotime($r['end_datetime']), strtotime($end.' 00:00:00'));
  for($t=$st; $t<$en; $t = strtotime('+1 day', $t)){
    $key = date('Y-m-d', $t);
    if (!isset($byDay[$key])) $byDay[$key] = [];
    $byDay[$key][] = $r;
  }
}

// ==== Helper ====
function thai_weekday_short($i){ // 1..7, Mon..Sun
  $map = [1=>'จ.',2=>'อ.',3=>'พ.',4=>'พฤ.',5=>'ศ.',6=>'ส.',7=>'อา.'];
  return $map[$i] ?? '';
}

?>
<?php render_header('แดชบอร์ด • Fleet'); ?>

  <!-- แบนเนอร์แสดงชื่อผู้ใช้เด่น ๆ -->
  <div class="card" style="display:flex;align-items:center;gap:12px;justify-content:space-between">
    <div style="display:flex;align-items:center;gap:12px">
      <div style="width:44px;height:44px;border-radius:50%;background:#e8f0fe;display:flex;align-items:center;justify-content:center;font-weight:700">
        <?= strtoupper(mb_substr($u['name'],0,1,'UTF-8')) ?>
      </div>
      <div>
        <div style="font-size:18px;font-weight:800;line-height:1.2">สวัสดี <?= h($u['name']) ?></div>
        <div style="opacity:.7">บทบาท: <?= h($u['role']) ?></div>
      </div>
    </div>
    <div class="kmutnb-badge" style="white-space:nowrap"><?= h(BRAND_FULL) ?></div>
  </div>

  <div class="stats">
    <div class="stat">
      <div class="label">จำนวนรถทั้งหมด</div>
      <div class="value"><?=h($total_vehicles)?></div>
      <div class="hint">พร้อมใช้งานในระบบ</div>
    </div>
    <div class="stat">
      <div class="label">คำขอรออนุมัติ</div>
      <div class="value"><?=h($pending)?></div>
      <div class="hint">รอดำเนินการ</div>
    </div>
    <div class="stat">
      <div class="label">ทริปที่จะถึง (7 วัน)</div>
      <div class="value"><?=h($upcoming)?></div>
      <div class="hint">ที่ได้รับอนุมัติแล้ว</div>
    </div>
    <div class="stat">
      <div class="label">ทริปที่กำลังใช้งาน</div>
      <div class="value"><?=h($active_trips)?></div>
      <div class="hint">ดำเนินการอยู่</div>
    </div>
    <div class="stat">
      <div class="label">ระยะทางรวมเดือนนี้</div>
      <div class="value"><?=h(number_format($s['km'],2))?> กม.</div>
      <div class="hint">ค่าน้ำมัน ≈ <?=h(number_format($s['cost'],2))?> ฿</div>
    </div>
  </div>

  <?php if($is_manager): ?>
  <div class="card">
    <h2>เมนูหลัก <span class="kmutnb-badge"><?=h(BRAND_FULL)?></span></h2>
    <div class="nav" style="margin-top:8px">
      <a href="vehicles.php"><button>รถยนต์</button></a>
      <a href="bookings_create.php"><button>ขอใช้รถ</button></a>
      <a href="bookings_my.php"><button>การจองของฉัน</button></a>
      <a href="bookings_admin.php"><button>อนุมัติ</button></a>
      <a href="trips_start.php"><button>เริ่มทริป</button></a>
      <a href="trips_end.php"><button>สิ้นสุดทริป</button></a>
      <a href="map_live.php"><button>แผนที่สด</button></a>
      <a href="report_monthly.php"><button>รายงานประจำเดือน</button></a>
      <a href="logout.php"><button class="secondary">ออกจากระบบ</button></a>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>ปฏิทินการใช้รถ</h2>
    <!-- ฟอร์มที่ “โชว์ชื่อผู้ใช้งาน” เด่นชัด -->
    <form method="get" style="margin-bottom:8px">
      <div style="margin:8px 0 14px 0; padding:10px 12px; border:1px dashed #cbd5e1; border-radius:10px; background:#f8fafc">
        <div style="font-size:12px; opacity:.7; margin-bottom:4px">ผู้ใช้งาน</div>
        <div style="font-weight:800; font-size:16px; line-height:1.2; margin-bottom:2px">
          <?= h($u['name']) ?>
          <span style="font-weight:600; font-size:12px; opacity:.7"> • <?= h($u['role']) ?></span>
        </div>
        <!-- เก็บชื่อส่งไปกับฟอร์มหากต้องใช้ต่อ -->
        <input type="hidden" name="user_name" value="<?= h($u['name']) ?>">
      </div>

      <?php
        $curY = (int)date('Y');
        $selY = (int)substr($month,0,4);
        $selM = (int)substr($month,5,2);
        $thaiMonths = [1=>'มกราคม',2=>'กุมภาพันธ์',3=>'มีนาคม',4=>'เมษายน',5=>'พฤษภาคม',6=>'มิถุนายน',7=>'กรกฎาคม',8=>'สิงหาคม',9=>'กันยายน',10=>'ตุลาคม',11=>'พฤศจิกายน',12=>'ธันวาคม'];
      ?>
      <label>เดือน</label>
      <select name="m" required>
        <?php for($i=1;$i<=12;$i++): ?>
          <option value="<?=$i?>" <?=$selM===$i?'selected':''?>><?=$thaiMonths[$i]?></option>
        <?php endfor; ?>
      </select>
      <label>ปี</label>
      <select name="year" required>
        <?php for($y=$curY-1;$y<=$curY+1;$y++): ?>
          <option value="<?=$y?>" <?=$selY===$y?'selected':''?>><?=$y+543?> (พ.ศ.)</option>
        <?php endfor; ?>
      </select>
      <label>รถ</label>
      <select name="vehicle_id">
        <option value="0">-- ทุกคัน --</option>
        <?php foreach($vehicles as $v): ?>
          <option value="<?=$v['id']?>" <?=$vehicle_id==$v['id']?'selected':''?>><?=h($v['plate_no'].' • '.$v['brand_model'])?></option>
        <?php endforeach; ?>
      </select>
      <button>ดูปฏิทิน</button>
    </form>

    <?php
      // Build calendar cells
      $first = strtotime($start);
      $first_w = (int)date('N', $first); // 1=Mon..7=Sun
      $days_in_month = (int)date('t', $first);
      $cells = [];
      for($i=1;$i<$first_w;$i++) $cells[] = null;      // leading blanks
      for($d=1;$d<=$days_in_month;$d++) $cells[] = $d; // days
      while(count($cells)%7) $cells[] = null;          // trailing blanks
    ?>

    <table>
      <tr>
        <?php for($w=1;$w<=7;$w++): ?>
          <th><?=thai_weekday_short($w)?></th>
        <?php endfor; ?>
      </tr>
      <?php for($i=0;$i<count($cells);$i+=7): ?>
        <tr>
          <?php for($j=0;$j<7;$j++): $d = $cells[$i+$j]; ?>
            <td style="vertical-align:top; min-width:140px">
              <?php if($d!==null):
                $dateKey = date('Y-m-', strtotime($start)) . str_pad($d,2,'0',STR_PAD_LEFT);
              ?>
                <div style="font-weight:700;margin-bottom:6px"><?=h($d)?></div>
                <?php if(!empty($byDay[$dateKey])):
                  $list = $byDay[$dateKey];
                  $count = 0;
                  foreach($list as $bk){
                    if ($count>=3){ echo '<div style="font-size:12px;color:#555">...และอื่น ๆ</div>'; break; }
                    $time = date('H:i', strtotime($bk['start_datetime'])) . '–' . date('H:i', strtotime($bk['end_datetime']));
                    $status = strtolower($bk['status']);
                    echo '<div style="font-size:12px;margin-bottom:4px"><span class="badge '.$status.'">'.h($bk['status']).'</span> '.h($bk['plate_no']).' <span style="color:#666">'.h($time).'</span></div>';
                    $count++;
                  }
                endif; ?>
              <?php endif; ?>
            </td>
          <?php endfor; ?>
        </tr>
      <?php endfor; ?>
    </table>
  </div>

  <?php if($is_manager): ?>
  <div class="card">
    <h2>รายการล่าสุด</h2>
    <?php
      $recent = $pdo->query("SELECT b.id, b.status, b.start_datetime, b.end_datetime, v.plate_no, u.name as uname
        FROM bookings b JOIN vehicles v ON v.id=b.vehicle_id JOIN users u ON u.id=b.user_id
        ORDER BY b.id DESC LIMIT 8")->fetchAll();
    ?>
    <table>
      <tr><th>#</th><th>ผู้ขอ</th><th>รถ</th><th>ช่วงเวลา</th><th>สถานะ</th></tr>
      <?php foreach($recent as $r): ?>
        <tr>
          <td><?=h($r['id'])?></td>
          <td><?=h($r['uname'])?></td>
          <td><?=h($r['plate_no'])?></td>
          <td><?=h($r['start_datetime'].' → '.$r['end_datetime'])?></td>
          <td><span class="badge <?=strtolower($r['status'])?>"><?=h($r['status'])?></span></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>

<?php render_footer(); ?>
