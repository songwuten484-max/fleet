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

$state = bin2hex(random_bytes(16));
$_SESSION['oauth2_state'] = $state;

$clientId     = ''; // TODO: แทนค่าจริง
$redirectUri  = 'https://roombooking.fba.kmutnb.ac.th/FBA_fleet/public/sso_callback.php'; // TODO: แก้เป็นของจริง
$scopes       = 'profile email student_info personnel_info';

$authUrl = sprintf(
  'https://sso.kmutnb.ac.th/auth/authorize?response_type=code&client_id=%s&redirect_uri=%s&scope=%s&state=%s',
  urlencode($clientId),
  urlencode($redirectUri),
  urlencode($scopes),
  urlencode($state)
);

header('Location: '.$authUrl);
exit;
?>
