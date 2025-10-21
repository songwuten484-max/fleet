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
require_once __DIR__.'/../inc/layout.php';
?>
<?php render_header('การจองของฉัน • Fleet'); ?>
<?php
require_once __DIR__.'/../inc/helpers.php';
require_once __DIR__.'/../inc/db.php';
$u = require_login();
$pdo = db();
$rows = $pdo->prepare("SELECT b.*, v.plate_no, v.brand_model FROM bookings b JOIN vehicles v ON v.id=b.vehicle_id WHERE b.user_id=? ORDER BY b.id DESC");
$rows->execute([$u['id']]);
$rows = $rows->fetchAll();
?>
  <div class="card">
    <h2>การจองของฉัน</h2>
    <table>
      <tr><th>#</th><th>รถ</th><th>ช่วงเวลา</th><th>สถานะ</th><th>ชั้นอนุมัติ</th></tr>
      <?php foreach($rows as $r): ?>
      <tr>
        <td><?=h($r['id'])?></td>
        <td><?=h($r['plate_no'].' • '.$r['brand_model'])?></td>
        <td><?=h($r['start_datetime'].' → '.$r['end_datetime'])?></td>
        <td><span class="badge <?=strtolower($r['status'])?>"><?=h($r['status'])?></span></td>
        <td><?=h($r['approval_stage'])?> / <?=h($r['approval_required'])?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

<?php render_footer(); ?>