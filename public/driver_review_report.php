<?php
// public/driver_review_report.php
declare(strict_types=1);

session_name('FLEETSESSID');
session_start();

require_once __DIR__.'/../inc/config.php';
require_once __DIR__.'/../inc/layout.php';
require_once __DIR__.'/../inc/helpers.php';
require_once __DIR__.'/../inc/db.php';

$pdo = db();
$u   = require_role(['ADMIN']); // สรุปแบบประเมิน: เฉพาะ ADMIN

/* --- สร้างตารางถ้ายังไม่มี (เพื่อให้หน้า report เปิดได้แน่นอน) --- */
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

/* --- ตัวกรอง --- */
$today = date('Y-m-d');
$firstOfMonth = date('Y-m-01');

$df = $_GET['date_from'] ?? $firstOfMonth;
$dt = $_GET['date_to']   ?? $today;
$vehicle_id = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0;
$driver_id  = isset($_GET['driver_id'])  ? (int)$_GET['driver_id']  : 0;

/* --- data อ้างอิง --- */
$vehicles = $pdo->query("SELECT id, plate_no, brand_model FROM vehicles WHERE active=1 ORDER BY plate_no")->fetchAll();
$drivers  = $pdo->query("SELECT id, name FROM users WHERE role='DRIVER' ORDER BY name")->fetchAll();

/* --- สร้าง WHERE + params รวมศูนย์ --- */
$where = " r.created_at >= ? AND r.created_at < DATE_ADD(?, INTERVAL 1 DAY) ";
$params = [$df, $dt];
if ($vehicle_id) { $where .= " AND r.vehicle_id=? "; $params[] = $vehicle_id; }
if ($driver_id)  { $where .= " AND r.driver_id=? ";  $params[] = $driver_id; }

/* --- ภาพรวม --- */
$sum = $pdo->prepare("
SELECT COUNT(*) as cnt, COALESCE(AVG(r.rating),0) as avg_rating
FROM driver_reviews r
WHERE $where
");
$sum->execute($params);
$summary = $sum->fetch();

/* --- Histogram การกระจายคะแนน 1..5 --- */
$hist = $pdo->prepare("
SELECT rating, COUNT(*) c
FROM driver_reviews r
WHERE $where
GROUP BY rating
");
$hist->execute($params);
$bucket = [1=>0,2=>0,3=>0,4=>0,5=>0];
foreach($hist->fetchAll() as $h){
  $r = (int)$h['rating'];
  if ($r>=1 && $r<=5) $bucket[$r] = (int)$h['c'];
}

/* --- ตารางตามคนขับ (ยังคงเป็นตาราง ไม่ทำกราฟเฉลี่ยแล้ว) --- */
$byDriver = $pdo->prepare("
SELECT r.driver_id, u.name as driver_name, COUNT(*) as cnt, COALESCE(AVG(r.rating),0) as avg_rating
FROM driver_reviews r
LEFT JOIN users u ON u.id=r.driver_id
WHERE $where
GROUP BY r.driver_id, u.name
ORDER BY cnt DESC, avg_rating DESC
");
$byDriver->execute($params);
$gDrivers = $byDriver->fetchAll();

/* --- ตารางตามรถ (ยังคงเป็นตาราง ไม่ทำกราฟเฉลี่ยแล้ว) --- */
$byVehicle = $pdo->prepare("
SELECT r.vehicle_id, v.plate_no, v.brand_model, COUNT(*) as cnt, COALESCE(AVG(r.rating),0) as avg_rating
FROM driver_reviews r
LEFT JOIN vehicles v ON v.id=r.vehicle_id
WHERE $where
GROUP BY r.vehicle_id, v.plate_no, v.brand_model
ORDER BY cnt DESC, avg_rating DESC
");
$byVehicle->execute($params);
$gVehicles = $byVehicle->fetchAll();

/* --- รายการล่าสุด --- */
$list = $pdo->prepare("
SELECT r.id, r.created_at, r.rating, r.comment,
       b.id AS booking_id, b.start_datetime, b.end_datetime,
       v.plate_no, v.brand_model,
       u.name as driver_name, ur.name as rater_name
FROM driver_reviews r
JOIN bookings b ON b.id = r.booking_id
JOIN vehicles v ON v.id = r.vehicle_id
LEFT JOIN users u  ON u.id  = r.driver_id
LEFT JOIN users ur ON ur.id = r.rater_user_id
WHERE $where
ORDER BY r.created_at DESC, r.id DESC
LIMIT 200
");
$list->execute($params);
$rows = $list->fetchAll();

render_header('สรุปแบบประเมินคนขับรถ • Fleet', '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>');
?>
  <div class="card">
    <h2>สรุปแบบประเมินคนขับรถ</h2>
    <form method="get" class="row" style="gap:8px; align-items:flex-end;">
      <div>
        <label>ตั้งแต่</label>
        <input type="date" name="date_from" value="<?=h($df)?>" required>
      </div>
      <div>
        <label>ถึง</label>
        <input type="date" name="date_to" value="<?=h($dt)?>" required>
      </div>
      <div>
        <label>รถ</label>
        <select name="vehicle_id">
          <option value="0">-- ทั้งหมด --</option>
          <?php foreach($vehicles as $v): ?>
            <option value="<?=$v['id']?>" <?=$vehicle_id===$v['id']?'selected':''?>><?=h($v['plate_no'].' • '.$v['brand_model'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>คนขับ</label>
        <select name="driver_id">
          <option value="0">-- ทั้งหมด --</option>
          <?php foreach($drivers as $d): ?>
            <option value="<?=$d['id']?>" <?=$driver_id===$d['id']?'selected':''?>><?=h($d['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><button>ดูสรุป</button></div>
    </form>

    <div class="stats" style="margin-top:8px">
      <div class="stat">
        <div class="label">จำนวนแบบประเมิน</div>
        <div class="value"><?=h((int)$summary['cnt'])?></div>
        <div class="hint">รายการ</div>
      </div>
      <div class="stat">
        <div class="label">คะแนนเฉลี่ย</div>
        <div class="value"><?=h(number_format((float)$summary['avg_rating'], 2))?></div>
        <div class="hint">เต็ม 5</div>
      </div>
    </div>
  </div>

  <div class="card">
    <h3>การกระจายคะแนน (1–5)</h3>
    <?php
      $labels = [1,2,3,4,5];
      $data   = [$bucket[1],$bucket[2],$bucket[3],$bucket[4],$bucket[5]];
    ?>
    <?php if(array_sum($data) === 0): ?>
      <div class="flash">ยังไม่มีข้อมูล</div>
    <?php else: ?>
      <canvas id="chartDist" height="120"></canvas>
      <script>
        const distLabels = <?=json_encode($labels)?>;
        const distData   = <?=json_encode($data)?>;
        new Chart(document.getElementById('chartDist'), {
          type: 'bar',
          data: {
            labels: distLabels.map(String),
            datasets: [{ label: 'จำนวนการให้คะแนน', data: distData }]
          },
          options: {
            scales: {
              y: { beginAtZero: true, precision: 0 },
              x: { title: { display: true, text: 'คะแนน' } }
            }
          }
        });
      </script>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>สรุปตามคนขับ (ตาราง)</h3>
    <?php if(empty($gDrivers)): ?>
      <div class="flash">ยังไม่มีข้อมูล</div>
    <?php else: ?>
      <table>
        <tr><th>คนขับ</th><th>จำนวนรีวิว</th><th>คะแนนเฉลี่ย</th></tr>
        <?php foreach($gDrivers as $r): ?>
          <tr>
            <td><?=h($r['driver_name'] ?: '-')?></td>
            <td><?=h($r['cnt'])?></td>
            <td><?=h(number_format($r['avg_rating'],2))?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>สรุปตามรถ (ตาราง)</h3>
    <?php if(empty($gVehicles)): ?>
      <div class="flash">ยังไม่มีข้อมูล</div>
    <?php else: ?>
      <table>
        <tr><th>รถ</th><th>จำนวนรีวิว</th><th>คะแนนเฉลี่ย</th></tr>
        <?php foreach($gVehicles as $r): ?>
          <tr>
            <td><?=h($r['plate_no'].' • '.$r['brand_model'])?></td>
            <td><?=h($r['cnt'])?></td>
            <td><?=h(number_format($r['avg_rating'],2))?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>รายการแบบประเมินล่าสุด</h3>
    <?php if(empty($rows)): ?>
      <div class="flash">ยังไม่มีข้อมูล</div>
    <?php else: ?>
      <table>
        <tr>
          <th>#</th><th>การจอง</th><th>รถ</th><th>คนขับ</th><th>ผู้ประเมิน</th><th>คะแนน</th><th>ความคิดเห็น</th><th>เวลา</th>
        </tr>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><?=h($r['id'])?></td>
            <td>#<?=h($r['booking_id'])?><br><small><?=h($r['start_datetime'].' → '.$r['end_datetime'])?></small></td>
            <td><?=h($r['plate_no'].' • '.$r['brand_model'])?></td>
            <td><?=h($r['driver_name'] ?: '-')?></td>
            <td><?=h($r['rater_name'] ?: '-')?></td>
            <td><?=h($r['rating'])?></td>
            <td><?=h($r['comment'] ?: '-')?></td>
            <td><?=h(date('d/m/Y H:i', strtotime($r['created_at'])))?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

<?php render_footer(); ?>
