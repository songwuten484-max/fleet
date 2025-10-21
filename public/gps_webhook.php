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
require_once __DIR__.'/../inc/helpers.php';
require_once __DIR__.'/../inc/db.php';
require_once __DIR__.'/../inc/line.php';
require_once __DIR__.'/../inc/geo.php';

$pdo = db();
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { http_response_code(400); echo json_encode(['error'=>'invalid json']); exit; }

$vid = (int)($input['vehicle_id'] ?? 0);
$lat = (float)($input['lat'] ?? 0);
$lng = (float)($input['lng'] ?? 0);
$speed = isset($input['speed']) ? (float)$input['speed'] : null;
$evt = $input['event_time'] ?? date('Y-m-d H:i:s');

$stmt = $pdo->prepare("INSERT INTO gps_events (vehicle_id, lat, lng, speed, event_time, raw) VALUES (?,?,?,?,?,JSON_OBJECT('raw', :raw))");
$stmt->bindValue(1,$vid);
$stmt->bindValue(2,$lat);
$stmt->bindValue(3,$lng);
$stmt->bindValue(4,$speed);
$stmt->bindValue(5,$evt);
$stmt->bindValue(':raw', json_encode($input, JSON_UNESCAPED_UNICODE));
$stmt->execute();

$outside = is_outside_geofence($lat,$lng);

$now = $evt;
$q = $pdo->prepare("SELECT b.*, u.line_user_id FROM bookings b JOIN users u ON u.id=b.user_id WHERE b.vehicle_id=? AND b.status='APPROVED' AND b.start_datetime <= ? AND b.end_datetime >= ? ORDER BY b.id DESC LIMIT 1");
$q->execute([$vid, $now, $now]);
$active = $q->fetch();

if ($outside) {
    $pdo->prepare("INSERT INTO alerts (vehicle_id, type, message) VALUES (?,?,?)")
        ->execute([$vid, 'GEOFENCE', "Vehicle $vid outside campus"]);
    $admins = $pdo->query("SELECT line_user_id FROM users WHERE role IN ('ADMIN','APPROVER_L1','APPROVER_L2') AND line_user_id IS NOT NULL")->fetchAll();
    foreach($admins as $a){ line_push($a['line_user_id'], [line_text("แจ้งเตือน: รถ ID $vid ออกจากมหาวิทยาลัย")]); }
}

if (!$active && $speed !== null && $speed > 5) {
    $pdo->prepare("INSERT INTO alerts (vehicle_id, type, message) VALUES (?,?,?)")
        ->execute([$vid, 'UNBOOKED_MOVE', "Vehicle $vid moved without booking"]);
    $admins = $pdo->query("SELECT line_user_id FROM users WHERE role IN ('ADMIN','APPROVER_L1','APPROVER_L2') AND line_user_id IS NOT NULL")->fetchAll();
    foreach($admins as $a){ line_push($a['line_user_id'], [line_text("แจ้งเตือน: รถ ID $vid เคลื่อนที่โดยไม่มีการจอง")]); }
}

header('Content-Type: application/json');
echo json_encode(['ok'=>true]);
?>