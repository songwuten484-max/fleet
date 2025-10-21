<?php
// ใช้ session ชุดเดียวกันทุกหน้า + ปลอดภัยหลัง proxy/HTTPS
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_name('FLEETSESSID');

  $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

  ini_set('session.cookie_secure', $isHttps ? '1' : '0');
  ini_set('session.cookie_httponly', '1');
  ini_set('session.cookie_samesite', 'Lax');

  // ไม่ใส่ domain ให้เป็น host-only cookie ลดโอกาส mismatch
  session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    // 'domain' => 'roombooking.fba.kmutnb.ac.th',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  session_start();
}
