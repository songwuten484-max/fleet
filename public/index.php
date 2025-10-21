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


// เตรียม state กัน CSRF
$state = bin2hex(random_bytes(16));
$_SESSION['oauth2_state'] = $state;

// ตั้งค่า SSO
$clientId     = 'VjJwIaQaqbZLJSYdfCIjpFpJ8MqpuTDp';       // <<< ใส่ค่าจริง
$redirectUri  = 'https://roombooking.fba.kmutnb.ac.th/FBA_fleet/public/sso_callback.php'; // <<< ใส่ค่าจริง
$scopes       = 'profile email student_info personnel_info';

// URL สำหรับเข้าสู่ระบบด้วย KMUTNB SSO
$login_url = sprintf(
  'https://sso.kmutnb.ac.th/auth/authorize?response_type=code&client_id=%s&redirect_uri=%s&scope=%s&state=%s',
  urlencode($clientId),
  urlencode($redirectUri),
  urlencode($scopes),
  urlencode($state)
);
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>เข้าสู่ระบบ - ระบบจองห้องเรียน</title>
  <style>
    body {
      margin: 0;
      font-family: 'Prompt', sans-serif;
      background: #f8fbff;
      color: #333;
      display: flex;
      justify-content: center;
      align-items: center;
      height: 100vh;
    }
    .login-card {
      background: #ffffff;
      padding: 40px;
      border-radius: 20px;
      box-shadow: 0 8px 20px rgba(0,0,0,0.1);
      text-align: center;
      width: 450px;
    }
    .login-card h1 {
      font-size: 22px;
      margin-bottom: 20px;
      color: #2c3e50;
    }
    .login-card p {
      font-size: 14px;
      margin-bottom: 30px;
      color: #666;
    }
 .sso-btn {
  display: block;                 /* เต็มความกว้าง */
  width: 100%;                    /* ยืดเต็มบล็อก */
  text-align: center;             /* ตัวอักษรตรงกลาง */
  background-color: #0d6efd;      /* ฟ้า KMUTNB */
  color: #fff;                    /* ตัวอักษรขาว */
  font-size: 14px;
  font-weight: bold;
  padding: 12px 0px;
  border-radius: 8px;             /* ขอบโค้ง */
  text-decoration: none;
  transition: background 0.3s, box-shadow 0.3s;
}
.sso-btn:hover {
  background-color: #0b5ed7;      /* ฟ้าเข้มเมื่อ hover */
  box-shadow: 0 4px 10px rgba(13,110,253,0.3);
}
    .footer {
      margin-top: 20px;
      font-size: 12px;
      color: #999;
    }
  </style>
</head>
<body>
  <div class="login-card">
     <img src="../assets/logo.png" alt="FBA Logo" class="logo" style="height:120px;" >
    <h1>ระบบจองรถคณะบริหารธุรกิจ</h1>
    <p>คณะบริหารธุรกิจ มหาวิทยาลัยเทคโนโลยีพระจอมเกล้าพระนครเหนือ</p>
    <img src="../assets/sso-icit-account.png" alt="SSO Logo" style="height:120px; margin:8px;">
    <a href="<?= htmlspecialchars($login_url) ?>" class="sso-btn">
  เข้าสู่ระบบด้วย KMUTNB SSO
</a>

  <div class="footer">
  แจ้งปัญหาการใช้งานระบบ : คุณทรงวุฒิ พิกุลรตน์ วิศวกรไฟฟ้า โทร. 5526
</div>
  </div>
</body>
</html>
