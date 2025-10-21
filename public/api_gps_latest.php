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
header('Content-Type: application/json; charset=utf-8');
$pdo = db();

$vehicle_id = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0;

if ($vehicle_id > 0) {
  $q = $pdo->prepare("SELECT * FROM latest_vehicle_location WHERE vehicle_id = ?");
  $q->execute([$vehicle_id]);
  $row = $q->fetch(PDO::FETCH_ASSOC);
  if ($row) { echo json_encode(['ok'=>true,'data'=>$row]); exit; }

  // fallback: ไม่ระบุคอลัมน์ เพื่อไม่พังถ้าไม่มี speed/heading
  $q2 = $pdo->prepare("SELECT * FROM gps_events WHERE vehicle_id=? ORDER BY event_time DESC LIMIT 1");
  $q2->execute([$vehicle_id]);
  $row = $q2->fetch(PDO::FETCH_ASSOC);
  if ($row) {
    if (!isset($row['speed']))   $row['speed'] = 0;
    if (!isset($row['heading'])) $row['heading'] = null;
  }
  echo json_encode(['ok'=>true,'data'=>$row]); exit;
} else {
  $rows = $pdo->query("SELECT * FROM latest_vehicle_location")->fetchAll(PDO::FETCH_ASSOC);
  echo json_encode(['ok'=>true,'data'=>$rows]); exit;
}
