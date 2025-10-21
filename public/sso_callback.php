<?php
declare(strict_types=1);

/* ---- Session ต้องใช้ชื่อเดียวกับหน้า login เสมอ ---- */
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

ini_set('display_errors', '0');
error_reporting(E_ALL);

/* ---- Helpers ---- */
function sso_log(string $msg): void {
  @file_put_contents('/tmp/room-sso.log', '['.date('c').'] '.$msg."\n", FILE_APPEND);
}
function pick(array $a, array $keys) {
  foreach ($keys as $k) {
    if (isset($a[$k]) && $a[$k] !== '' && $a[$k] !== null) return $a[$k];
  }
  return null;
}
function array_get(array $a, array $path) {
  $cur = $a;
  foreach ($path as $p) {
    if (!is_array($cur) || !array_key_exists($p, $cur)) return null;
    $cur = $cur[$p];
  }
  return $cur;
}

try {
  /* ---------- ตรวจ code / state ---------- */
  if (!isset($_GET['code'], $_GET['state'])) {
    throw new Exception('Missing code/state from SSO');
  }
  if (empty($_SESSION['oauth2_state']) || !hash_equals((string)$_SESSION['oauth2_state'], (string)$_GET['state'])) {
    throw new Exception('Invalid state (CSRF)');
  }
  // ป้องกัน replay: ใช้แล้วลบทันที
  unset($_SESSION['oauth2_state']);

  /* ---------- ค่าคงที่ SSO (ต้องตรงกับที่ตั้งไว้ใน SSO Console) ---------- */
  $CLIENT_ID     = getenv('KMUTNB_SSO_CLIENT_ID')     ?: 'VjJwIaQaqbZLJSYdfCIjpFpJ8MqpuTDp';
  $CLIENT_SECRET = getenv('KMUTNB_SSO_CLIENT_SECRET') ?: 'WSiNj83bEJiRgI3OsqlRfdDpFMooL76NlPnYoyPxD0ytccoOpM7jscAbC29qN1Up';
  $REDIRECT_URI  = getenv('KMUTNB_SSO_REDIRECT_URI')  ?: 'https://roombooking.fba.kmutnb.ac.th/FBA_fleet/public/sso_callback.php';

  /* ---------- แลก code -> token ---------- */
  $tokenCh = curl_init('https://sso.kmutnb.ac.th/auth/token');
  $postData = http_build_query([
    'grant_type'   => 'authorization_code',
    'code'         => $_GET['code'],
    'redirect_uri' => $REDIRECT_URI
  ]);
  curl_setopt_array($tokenCh, [
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
      'Authorization: Basic '.base64_encode($CLIENT_ID.':'.$CLIENT_SECRET),
      'Content-Type: application/x-www-form-urlencoded',
      'Accept: application/json'
    ],
    CURLOPT_POSTFIELDS     => $postData,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_FOLLOWLOCATION => true,
  ]);
  $tokenRes  = curl_exec($tokenCh);
  if ($tokenRes === false) throw new Exception('Curl token error: '.curl_error($tokenCh));
  $tokenHttp = curl_getinfo($tokenCh, CURLINFO_HTTP_CODE);
  curl_close($tokenCh);

  sso_log('TOKEN HTTP='.$tokenHttp.' BODY='.$tokenRes);
  if ($tokenHttp >= 400) throw new Exception('Token endpoint error HTTP '.$tokenHttp);

  $tok = json_decode($tokenRes, true);
  if (!is_array($tok) || empty($tok['access_token'])) throw new Exception('access_token not found');
  $accessToken = (string)$tok['access_token'];

  /* ---------- เรียก userinfo ---------- */
  $uiCh = curl_init('https://sso.kmutnb.ac.th/resources/userinfo');
  curl_setopt_array($uiCh, [
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer '.$accessToken, 'Accept: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_FOLLOWLOCATION => true,
  ]);
  $uiRes  = curl_exec($uiCh);
  if ($uiRes === false) throw new Exception('Curl userinfo error: '.curl_error($uiCh));
  $uiHttp = curl_getinfo($uiCh, CURLINFO_HTTP_CODE);
  curl_close($uiCh);

  sso_log('USERINFO HTTP='.$uiHttp.' RAW='.$uiRes);
  if ($uiHttp >= 400) throw new Exception('UserInfo endpoint error HTTP '.$uiHttp);

  $raw = json_decode($uiRes, true);
  if (!is_array($raw)) throw new Exception('Userinfo is not valid JSON');

  $u = isset($raw['user_info']) && is_array($raw['user_info']) ? $raw['user_info'] : $raw;

  // map ฟิลด์ที่เอกสาร SSO ระบุ (profile / email)
  $username = pick($u, ['username','preferred_username','account','user'])
           ?: array_get($u, ['profile','username'])
           ?: array_get($u, ['profile','preferred_username']);
  $display  = pick($u, ['display_name','name','fullname'])
           ?: array_get($u, ['profile','display_name']) ?: $username;
  $email    = pick($u, ['email','mail']) ?: array_get($u, ['profile','email']);
  if (!$username && $email) $username = strstr($email, '@', true);
  $accountType = pick($u, ['account_type','type']) ?: array_get($u, ['profile','account_type']);

  if (!$username) {
    sso_log('PARSE FAIL JSON='.json_encode($raw, JSON_UNESCAPED_UNICODE));
    throw new Exception('Invalid userinfo (no username-like field)');
  }

  /* ---------- เชื่อม DB (MySQLi) ---------- */
  $dbFile = __DIR__ . '/../inc/db2.php';
  if (!file_exists($dbFile)) throw new Exception('DB file not found: '.$dbFile);
  require_once $dbFile;

  if (!isset($conn) || !($conn instanceof mysqli)) {
    throw new Exception('config/db2.php must provide $conn (mysqli)');
  }

  /* ---------- upsert ผู้ใช้ (ให้ตรง schema ตาราง users) ---------- */
  $userId = null;
  $eml = $email ?? '';

  // หา user เดิมด้วย email หรือ sso_username
$sel = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $sel->bind_param("s", $eml);
  $sel->execute();
  $selRes = $sel->get_result();

  
  if ($row = $selRes->fetch_assoc()) {
    $userId = (int)$row['id'];

    // UPDATE: ตัดคอลัมน์ที่ไม่มีใน schema ออก และแก้จำนวน bind ให้ตรงกับ ?
    $upd = $conn->prepare("UPDATE users SET 
      sso_username = ?, 
      sso_account_type = ?,
      name = ?, 
      email = ?
      WHERE id = ?");
    $upd->bind_param("ssssi", $username, $accountType, $display, $eml, $userId);
    $upd->execute();
    $upd->close();

  } else {
    // INSERT: ใช้เฉพาะคอลัมน์ที่มีจริงในตาราง; created_at มี default CURRENT_TIMESTAMP อยู่แล้ว
    $role = 'USER';
    $ins = $conn->prepare("INSERT INTO users
      (name, email, role, sso_username, sso_account_type)
      VALUES (?,?,?,?,?)");
    $ins->bind_param("sssss", $display, $eml, $role, $username, $accountType);
    $ins->execute();
    $userId = (int)$conn->insert_id;
    $ins->close();
  }
  $sel->close();

  /* ---------- โหลดผู้ใช้เต็มแถวแล้วเก็บ session ---------- */
  $get = $conn->prepare("SELECT id, name, email, role, sso_username, sso_account_type FROM users WHERE id = ? LIMIT 1");
  $get->bind_param("i", $userId);
  $get->execute();
  $result = $get->get_result();
  $userFull = $result->fetch_assoc();
  $get->close();

  if (!$userFull) throw new Exception('Cannot fetch user row after upsert');

  session_regenerate_id(true);
  $_SESSION['user'] = $userFull;
  session_write_close();

  /* ---------- กลับหน้า dashboard ---------- */
  header('Location: ./dashboard.php');
  exit;

} catch (Throwable $e) {
  sso_log('ERROR: '.$e->getMessage());
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo "SSO error: ".$e->getMessage()."\n";
  echo "ดูรายละเอียดที่ /tmp/room-sso.log\n";
  exit;
}
