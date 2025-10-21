<?php
// public/api_check_availability.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

session_name('FLEETSESSID');
session_set_cookie_params([
  'lifetime' => 0,
  'path'     => '/',
  'domain'   => 'roombooking.fba.kmutnb.ac.th',
  'secure'   => true,
  'httponly' => true,
  'samesite' => 'Lax',
]);
session_start();

require_once __DIR__.'/../inc/db.php';

try {
  $vehicle_id = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0;
  $start = $_GET['start'] ?? '';
  $end   = $_GET['end'] ?? '';

  if (!$vehicle_id || !$start || !$end || strtotime($end) <= strtotime($start)) {
    echo json_encode(['ok'=>true, 'available'=>false, 'conflicts'=>[]]); exit;
  }

  $pdo = db();

  // เงื่อนไขชน: (มีทริปที่ยังไม่สิ้นสุด) OR (ไม่มีทริป และ NOW()<end_datetime)
  $sql = "
    SELECT b.id, b.start_datetime, b.end_datetime, b.status
    FROM bookings b
    WHERE b.vehicle_id = ?
      AND b.status IN ('PENDING','APPROVED_L1','APPROVED')
      AND NOT (b.end_datetime <= ? OR b.start_datetime >= ?)
      AND (
            EXISTS (
              SELECT 1 FROM trips t1
              WHERE t1.booking_id = b.id
                AND (t1.actual_end IS NULL OR t1.actual_end = '0000-00-00 00:00:00')
            )
            OR
            (
              NOT EXISTS (SELECT 1 FROM trips t0 WHERE t0.booking_id = b.id)
              AND NOW() < b.end_datetime
            )
          )
    ORDER BY b.start_datetime ASC
  ";
  $q = $pdo->prepare($sql);
  $q->execute([$vehicle_id, $start, $end]);
  $conflicts = $q->fetchAll();

  echo json_encode([
    'ok' => true,
    'available' => count($conflicts) === 0,
    'conflicts' => array_map(fn($r)=>[
      'id' => (int)$r['id'],
      'status' => (string)$r['status'],
      'start' => (string)$r['start_datetime'],
      'end'   => (string)$r['end_datetime'],
    ], $conflicts),
  ]);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
