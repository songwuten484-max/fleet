<?php
/**
 * KMUTNB FBA Fleet — Global Config (Latest, fixed)
 */
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'fleetdb');
define('DB_USER', 'roombookingfba');
define('DB_PASS', 'gnH!#987*');

// ค่า SSO ให้ตรงกับ console
define('SSO_CLIENT_ID',    'VjJwIaQaqbZLJSYdfCIjpFpJ8MqpuTDp');             // TODO: แก้เป็นค่าจริง
define('SSO_CLIENT_SECRET','WSiNj83bEJiRgI3OsqlRfdDpFMooL76NlPnYoyPxD0ytccoOpM7jscAbC29qN1Up'); // ใช้ตอน /token
define('SSO_REDIRECT_URI', 'https://roombooking.fba.kmutnb.ac.th/FBA_fleet/public/sso_callback.php');
define('SSO_AUTH_BASE',    'https://sso.kmutnb.ac.th/auth/authorize');
define('SSO_TOKEN_URL',    'https://sso.kmutnb.ac.th/auth/token');
define('SSO_SCOPES',       'profile email');
define('SSO_STATE_SECRET', 'change_this_to_a_random_32_bytes_secret________');

if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Asia/Bangkok');

/* ---------- Base URLs ---------- */
$__scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
// NOTE: fallback host must NOT include scheme (https://). Use hostname only.
$__host   = $_SERVER['HTTP_HOST'] ?? '3c8388ce6b84.ngrok-free.app';
$__dir    = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/.');

if (!defined('BASE_URL'))    define('BASE_URL', $__dir ?: '');
if (!defined('BASE_ORIGIN')) define('BASE_ORIGIN', "$__scheme://$__host");
if (!defined('BASE_ABS'))    define('BASE_ABS', rtrim(BASE_ORIGIN . BASE_URL, '/'));

/* ---------- Branding ---------- */
if (!defined('FACULTY_NAME'))    define('FACULTY_NAME', 'คณะบริหารธุรกิจ');
if (!defined('UNIVERSITY_NAME')) define('UNIVERSITY_NAME', 'มหาวิทยาลัยเทคโนโลยีพระจอมเกล้าพระนครเหนือ');
if (!defined('BRAND_FULL'))      define('BRAND_FULL', FACULTY_NAME.' • '.UNIVERSITY_NAME);
if (!defined('BRAND_LOGO_REL'))  define('BRAND_LOGO_REL', '../assets/logo.png'); // เปลี่ยน path ได้ตามจริง

/* ---------- Geofence (KMUTNB Rayong Campus) ---------- */
/* ศูนย์กลาง: ต.หนองละลอก อ.บ้านค่าย จ.ระยอง */
if (!defined('GEOFENCE_CENTER_LAT')) define('GEOFENCE_CENTER_LAT', 12.826);
if (!defined('GEOFENCE_CENTER_LNG')) define('GEOFENCE_CENTER_LNG', 101.2168);
/* วงกลมรัศมี 1.5 กม. */
if (!defined('GEOFENCE_RADIUS_M'))   define('GEOFENCE_RADIUS_M', 300);
/* ถ้าต้องการใช้ขอบเขตแบบโพลิกอน ให้ใส่ค่า JSON ในตัวแปรด้านล่าง แล้วระบบจะใช้โพลิกอนแทนวงกลมอัตโนมัติ */
if (!defined('GEOFENCE_POLYGON'))    define('GEOFENCE_POLYGON', '');

/* ---------- SSO (KMUTNB OAuth2) ----------
   เปิด/ปิดได้ด้วย SSO_ENABLED. เมื่อเปิดจะมีปุ่ม "เข้าสู่ระบบด้วย KMUTNB SSO" ในหน้า login.php
   ควรตั้งค่า CLIENT_ID/SECRET/REDIRECT_URI ให้ตรงกับที่ลงทะเบียนไว้กับ SSO
------------------------------------------------ */
if (!defined('SSO_ENABLED'))       define('SSO_ENABLED', true);
if (!defined('SSO_CLIENT_ID'))     define('SSO_CLIENT_ID', getenv('SSO_CLIENT_ID') ?: 'YOUR_CLIENT_ID');
if (!defined('SSO_CLIENT_SECRET')) define('SSO_CLIENT_SECRET', getenv('SSO_CLIENT_SECRET') ?: 'YOUR_CLIENT_SECRET');
// ปล่อยเป็น BASE_ABS.'/sso_callback.php' เพื่อรองรับ subfolder (เมื่อหน้าอยู่ใน /public จะได้ .../public/sso_callback.php)
if (!defined('SSO_REDIRECT_URI'))  define('SSO_REDIRECT_URI', getenv('SSO_REDIRECT_URI') ?: (BASE_ABS.'/sso_callback.php'));
if (!defined('SSO_SCOPE'))         define('SSO_SCOPE', 'profile email'); // เพิ่ม personnel_info / student_info ได้

/* ---------- LINE Official Account (Messaging API) ---------- */
if (!defined('LINE_CHANNEL_ACCESS_TOKEN')) define('LINE_CHANNEL_ACCESS_TOKEN', getenv('LINE_CHANNEL_ACCESS_TOKEN') ?: 'kElDU+Y5plL+vDohTottffCVNDt0vgngCy6x6eaOMUVr/styFHa73A5jclbCAmshy67gnPdNsnPWo+iuhULWdQDioSC4Py1a1eNnoY5pjyHLnOgh7MPUvAvpldC+axUp814bYIeyx3DvChyRAaRMcQdB04t89/1O/w1cDnyilFU=');
if (!defined('LINE_CHANNEL_SECRET'))       define('LINE_CHANNEL_SECRET', getenv('LINE_CHANNEL_SECRET') ?: 'c8ce546194d63978e62308dab1237401');

/* ---------- Misc feature flags (ปรับตามนโยบาย) ---------- */
// ระดับการอนุมัติค่าเริ่มต้น (ระบบปัจจุบันใช้ 1 ขั้น: หัวหน้าสำนักงานคณบดี)
if (!defined('APPROVAL_LEVELS')) define('APPROVAL_LEVELS', 1);

/* ---------- Fuel/Cost defaults ---------- */
/* อัตราค่าน้ำมันโดยประมาณต่อกิโลเมตร (หน่วย: บาท/กม.) */
if (!defined('DEFAULT_FUEL_RATE_PER_KM')) define('DEFAULT_FUEL_RATE_PER_KM', 4.50);

// หมายเหตุ: การตั้งค่าฐานข้อมูลหลักอยู่ในส่วนนิยามคอนสแตนต์ด้านบน (DB_*)
// หากต้องการใช้ Environment variables แทน ให้ลบ define(DB_*) แล้วตั้งค่าใน Apache/.env
