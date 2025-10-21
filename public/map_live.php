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
require_once __DIR__.'/../inc/helpers.php';
require_once __DIR__.'/../inc/layout.php';
require_once __DIR__.'/../inc/db.php';

$u = require_role(['ADMIN','APPROVER_L1','APPROVER_L2']); // จำกัดสิทธิ์ดูแผนที่สด
?>
<?php render_header('แผนที่สด • Fleet'); ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>

<style>
  #map { height: 70vh; border-radius: 12px; }
  .legend { margin-top:8px; font-size:12px; opacity:.8; }
  .status { margin-top:8px; font-size:12px; opacity:.8; }
</style>

<div class="card">
  <h2>แผนที่สด</h2>
  <div id="map"></div>
  <div class="status" id="status">กำลังโหลด…</div>
  <div class="legend">อัปเดตทุก 10 วินาที • ข้อมูลจากตารางสรุปล่าสุดต่อคัน</div>
</div>

<script>
const API = 'api_gps_latest.php';
let map, markers = {};
let firstFit = true;
let geofenceShape = null;

// ตั้งค่าจาก config (มีได้หรือไม่มีก็ได้)
const CFG = {
  center: [<?= defined('GEOFENCE_CENTER_LAT') ? GEOFENCE_CENTER_LAT : '13.736' ?>, <?= defined('GEOFENCE_CENTER_LNG') ? GEOFENCE_CENTER_LNG : '100.523' ?>],
  radius: <?= defined('GEOFENCE_RADIUS_M') ? GEOFENCE_RADIUS_M : 300 ?> // เมตร (ถ้าไม่ตั้งไว้ จะใช้ 300m)
};

function initMap() {
  map = L.map('map').setView(CFG.center, 15);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors'
  }).addTo(map);

  // วาด “วงกลม geofence” กลับมา
  geofenceShape = L.circle(CFG.center, {
    radius: CFG.radius,
    color: '#1d4ed8',       // กรอบน้ำเงิน
    weight: 2,
    fillColor: '#60a5fa',   // พื้นน้ำเงินอ่อน
    fillOpacity: 0.08
  }).addTo(map);
}

function fmtTime(s) {
  if (!s) return '-';
  // แสดงตามเวลาท้องถิ่น (DB ส่วนใหญ่เก็บ local)
  const d = new Date(s.replace(' ', 'T'));
  return isNaN(d) ? s : d.toLocaleString();
}

async function loadLatest() {
  const $status = document.getElementById('status');
  try {
    const res = await fetch(API + '?ts=' + Date.now(), { cache: 'no-store' });
    const json = await res.json();
    if (!json.ok) { $status.textContent = 'โหลดข้อมูลไม่สำเร็จ'; return; }

    const data = Array.isArray(json.data) ? json.data : (json.data ? [json.data] : []);
    const seen = new Set();
    const latlngs = [];

    data.forEach(p => {
      const id  = Number(p.vehicle_id);
      const lat = parseFloat(p.lat);
      const lng = parseFloat(p.lng);
      if (!id || !isFinite(lat) || !isFinite(lng)) return;

      seen.add(id);
      const html = `<b>Vehicle #${id}</b><br>Lat: ${lat.toFixed(6)}<br>Lng: ${lng.toFixed(6)}<br>Speed: ${(p.speed ?? 0)} km/h<br>Time: ${fmtTime(p.event_time)}`;

      // ใช้ "จุดวงกลม" ให้ดูเป็น dot ชัด ๆ
      if (!markers[id]) {
        markers[id] = L.circleMarker([lat, lng], {
          radius: 7, color: '#10b981', weight: 2, fillOpacity: 0.9
        }).addTo(map).bindPopup(html);
      } else {
        markers[id].setLatLng([lat, lng]).setPopupContent(html);
      }
      latlngs.push([lat, lng]);
    });

    // ลบจุดของคันที่ไม่อยู่ในชุดล่าสุด
    Object.keys(markers).forEach(k => {
      const id = Number(k);
      if (!seen.has(id)) {
        map.removeLayer(markers[id]);
        delete markers[id];
      }
    });

    if (firstFit) {
      // ขยายให้เห็นทั้งวง geofence และยานพาหนะทั้งหมด
      const bounds = L.latLngBounds(latlngs.length ? latlngs : [CFG.center]);
      // รวมขอบเขตของวงกลม geofence ด้วย
      const circleBounds = geofenceShape ? geofenceShape.getBounds() : null;
      const all = circleBounds ? bounds.extend(circleBounds) : bounds;
      map.fitBounds(all.pad(0.2));
      firstFit = false;
    }

    $status.textContent = `ยานพาหนะที่แสดง: ${seen.size} คัน • อัปเดตล่าสุด: ${new Date().toLocaleTimeString()}`;
  } catch (e) {
    console.error(e);
    document.getElementById('status').textContent = 'เกิดข้อผิดพลาดในการโหลดข้อมูล';
  }
}

initMap();
loadLatest();
setInterval(loadLatest, 10000);
</script>

<?php render_footer(); ?>
