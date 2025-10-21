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
require_once __DIR__.'/../inc/helpers.php';
require_once __DIR__.'/../inc/db.php';

$u = require_role(['ADMIN']);
$pdo = db();

$msg = '';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $line = trim($_POST['line_user_id'] ?? '');
    if($name && $email && $password){
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("INSERT INTO users (name,email,password_hash,role,line_user_id) VALUES (?,?,?,?,?)");
        $stmt->execute([$name,$email,$hash,'DRIVER',$line ?: null]);
        $msg = 'เพิ่มผู้ใช้คนขับรถเรียบร้อย';
    } else {
        $msg = 'กรุณากรอกข้อมูลให้ครบ';
    }
}

$drivers = $pdo->query("SELECT id,name,email,line_user_id,created_at FROM users WHERE role='DRIVER' ORDER BY id DESC")->fetchAll();
?>
<?php render_header('ผู้ใช้ (คนขับรถ) • Fleet'); ?>
  <?php if($msg): ?><div class="flash"><?=h($msg)?></div><?php endif; ?>
  <div class="card">
    <h2>เพิ่มผู้ใช้ (คนขับรถ)</h2>
    <form method="post">
      <?php echo form_brand_badge(); ?>
      <label>ชื่อ - สกุล</label><input name="name" required>
      <label>อีเมล</label><input name="email" type="email" required>
      <label>รหัสผ่าน</label><input name="password" type="password" required>
      <label>LINE User ID (ถ้ามี)</label><input name="line_user_id">
      <button>บันทึก</button>
      <a class="btn secondary" href="dashboard.php">กลับ</a>
    </form>
  </div>
  <div class="card">
    <h2>รายการคนขับรถ</h2>
    <table>
      <tr><th>#</th><th>ชื่อ</th><th>อีเมล</th><th>LINE User ID</th><th>สร้างเมื่อ</th></tr>
      <?php foreach($drivers as $d): ?>
      <tr>
        <td><?=h($d['id'])?></td>
        <td><?=h($d['name'])?></td>
        <td><?=h($d['email'])?></td>
        <td><?=h($d['line_user_id'])?></td>
        <td><?=h($d['created_at'])?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
<?php render_footer(); ?>