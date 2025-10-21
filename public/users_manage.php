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
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='create'){
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'USER';
    $line = trim($_POST['line_user_id'] ?? '');
    if($name && $email && $password){
        try {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO users (name,email,password_hash,role,line_user_id) VALUES (?,?,?,?,?)");
            $stmt->execute([$name,$email,$hash,$role,$line ?: null]);
            $msg = 'เพิ่มผู้ใช้เรียบร้อย';
        } catch (Exception $e) {
            $msg = 'เพิ่มผู้ใช้ไม่สำเร็จ: ' . $e->getMessage();
        }
    } else {
        $msg = 'กรุณากรอกข้อมูลให้ครบ (ชื่อ, อีเมล, รหัสผ่าน)';
    }
}

$users = $pdo->query("SELECT id,name,email,role,line_user_id,created_at FROM users ORDER BY id DESC")->fetchAll();
$roles = ['ADMIN'=>'ADMIN','APPROVER_L1'=>'APPROVER_L1','APPROVER_L2'=>'APPROVER_L2','USER'=>'USER','DRIVER'=>'DRIVER'];
?>
<?php render_header('จัดการผู้ใช้งาน • Fleet'); ?>
  <?php if($msg): ?><div class="flash"><?=h($msg)?></div><?php endif; ?>
  <div class="card">
    <h2>เพิ่มผู้ใช้งาน</h2>
    <form method="post">
      <?php echo form_brand_badge(); ?>
      <input type="hidden" name="action" value="create">
      <label>ชื่อ - สกุล</label><input name="name" required>
      <label>อีเมล</label><input name="email" type="email" required>
      <label>รหัสผ่าน</label><input name="password" type="password" required>
      <label>บทบาท</label>
      <select name="role" required>
        <?php foreach($roles as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?>
      </select>
      <label>LINE User ID (ถ้ามี)</label><input name="line_user_id">
      <button>บันทึก</button>
      <a class="btn secondary" href="dashboard.php">กลับ</a>
    </form>
  </div>
  <div class="card">
    <h2>ผู้ใช้งานทั้งหมด</h2>
    <table>
      <tr><th>#</th><th>ชื่อ</th><th>อีเมล</th><th>บทบาท</th><th>LINE User ID</th><th>สร้างเมื่อ</th><th>การจัดการ</th></tr>
      <?php foreach($users as $d): ?>
      <tr>
        <td><?=h($d['id'])?></td>
        <td><?=h($d['name'])?></td>
        <td><?=h($d['email'])?></td>
        <td><span class="badge"><?=h($d['role'])?></span></td>
        <td><?=h($d['line_user_id'])?></td>
        <td><?=h($d['created_at'])?></td>
        <td><a class="btn secondary" href="users_edit.php?id=<?=h($d['id'])?>">แก้ไข</a></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
<?php render_footer(); ?>