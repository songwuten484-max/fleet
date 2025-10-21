<?php
/**
 * api_gps_ingest.php — รองรับ device_uid (Traccar uniqueId) → map เป็น vehicle_id ผ่าน vehicles.gps_device_id
 * - รับพิกัดจาก Traccar (หรืออุปกรณ์อื่น)
 * - บันทึก gps_events (ตรวจคอลัมน์จริงแบบไดนามิก)
 * - UPSERT latest_vehicle_location
 * - แปลง speed จาก knots → km/h ถ้าระบุ speed_unit=knots
 * - แจ้งเตือน geofence / เคลื่อนที่โดยไม่มีทริป (คง logic เบา ๆ)
 * 
 */

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
require_once __DIR__.'/../inc/helpers.php';
require_once __DIR__.'/../inc/db.php';

$geoFile = __DIR__.'/../inc/geo.php';
if (file_exists($geoFile)) require_once $geoFile;

header('Content-Type: application/json; charset=utf-8');

function _push_line_to_admins(PDO $pdo, string $message): void {
  try {
    $stmt = $pdo->query("SELECT line_user_id FROM users WHERE role='ADMIN' AND line_user_id IS NOT NULL");
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $lid) {
      if (!$lid) continue;
      if (function_exists('line_push'))      @line_push($lid, $message);
      elseif (function_exists('line_notify'))@line_notify($lid, $message);
    }
  } catch (Throwable $e) { /* swallow */ }
}

try {
  $pdo = db();

  // ---- รับพารามิเตอร์ ----
  // ทางเลือก 1: ส่ง vehicle_id มาตรง ๆ (ตามเดิม)
  $vehicle_id = (int)($_REQUEST['vehicle_id'] ?? 0);
  // ทางเลือก 2: ส่ง device_uid (Traccar uniqueId) มา แล้ว map เป็น vehicle_id จาก vehicles.gps_device_id
  $device_uid = trim((string)($_REQUEST['device_uid'] ?? $_REQUEST['unique_id'] ?? $_REQUEST['uid'] ?? ''));

  $lat        = isset($_REQUEST['lat']) ? (float)$_REQUEST['lat'] : 0;
  $lng        = isset($_REQUEST['lng']) ? (float)$_REQUEST['lng'] : 0;
  $speed      = isset($_REQUEST['speed']) ? (float)$_REQUEST['speed'] : 0;
  $heading    = isset($_REQUEST['heading']) ? (float)$_REQUEST['heading'] : null;
  $event_time = $_REQUEST['event_time'] ?? null;  // format: YYYY-MM-DD HH:MM:SS (local/utc ตามระบบ)
  if (!$event_time) $event_time = gmdate('Y-m-d H:i:s');

  // แปลงหน่วย speed ถ้าส่งมาเป็น knots (Traccar มาตรฐาน)
  $unit = strtolower((string)($_REQUEST['speed_unit'] ?? ''));
  if ($unit === 'knots' || $unit === 'knot') {
    if (is_numeric($speed)) $speed = $speed * 1.852; // knots → km/h
  }

  // Map device_uid → vehicle_id ถ้ายังไม่มี
  if ($vehicle_id <= 0 && $device_uid !== '') {
    $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE gps_device_id = ? LIMIT 1");
    $stmt->execute([$device_uid]);
    $vehicle_id = (int)$stmt->fetchColumn();
  }

  if ($vehicle_id <= 0 || !$lat || !$lng) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'missing params or unknown device (vehicle_id/device_uid)']);
    exit;
  }

  // ตรวจ schema gps_events เพื่อประกอบ INSERT ให้ตรงคอลัมน์จริง
  $cols = [];
  foreach ($pdo->query("SHOW COLUMNS FROM gps_events") as $c) {
    $cols[$c['Field']] = true;
  }
  $hasSpeed     = isset($cols['speed']);
  $hasHeading   = isset($cols['heading']);
  $hasCreatedAt = isset($cols['created_at']);
  $hasDeviceUid = isset($cols['device_uid']); // เผื่อมีคอลัมน์เก็บ uid

  $pdo->beginTransaction();

  // ---- 1) INSERT gps_events (dynamic fields) ----
  $fields = ['vehicle_id','lat','lng','event_time'];
  $values = ['?','?','?','?'];
  $params = [$vehicle_id, $lat, $lng, $event_time];

  if ($hasSpeed)     { $fields[]='speed';     $values[]='?';   $params[]=$speed;   }
  if ($hasHeading)   { $fields[]='heading';   $values[]='?';   $params[]=$heading; }
  if ($hasDeviceUid) { $fields[]='device_uid';$values[]='?';   $params[]=$device_uid ?: null; }
  if ($hasCreatedAt) { $fields[]='created_at';$values[]='NOW()'; }

  $sql = "INSERT INTO gps_events (".implode(',', $fields).") VALUES (".implode(',', $values).")";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  // ---- 2) UPSERT latest_vehicle_location ----
  $up = $pdo->prepare("
    INSERT INTO latest_vehicle_location (vehicle_id, lat, lng, speed, heading, event_time)
    VALUES (:v,:lat,:lng,:speed,:heading,:time)
    ON DUPLICATE KEY UPDATE
      lat=VALUES(lat), lng=VALUES(lng), speed=VALUES(speed), heading=VALUES(heading),
      event_time=VALUES(event_time), updated_at=NOW()
  ");
  $up->execute([
    'v'=>$vehicle_id, 'lat'=>$lat, 'lng'=>$lng,
    'speed'=>$speed, 'heading'=>$heading, 'time'=>$event_time
  ]);

  // ---- 3) แจ้งเตือน geofence / unbooked move (แบบย่อ) ----
  $messages = [];

  $inside = true;
  if (function_exists('geo_point_in_geofence')) {
    try { $inside = (bool)geo_point_in_geofence($lat, $lng); } catch (Throwable $e) { $inside = true; }
  }
  if (!$inside) $messages[] = "แจ้งเตือน: รถ {$vehicle_id} ออกจากพื้นที่ที่กำหนด";

  $activeClause = " (actual_end IS NULL OR actual_end='0000-00-00 00:00:00') ";
  $q = $pdo->prepare("SELECT id FROM trips WHERE vehicle_id=? AND {$activeClause} LIMIT 1");
  $q->execute([$vehicle_id]);
  $activeTripId = $q->fetchColumn();

  if (!$activeTripId && $speed > 5) {
    $messages[] = "แจ้งเตือน: รถ {$vehicle_id} เคลื่อนที่โดยไม่มีทริป (≈ ".number_format($speed,1)." km/h)";
  }
  foreach ($messages as $m) _push_line_to_admins($pdo, $m);

  $pdo->commit();
  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
