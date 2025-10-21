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
$roles = ['ADMIN'=>'ADMIN','APPROVER_L1'=>'APPROVER_L1','APPROVER_L2'=>'APPROVER_L2','USER'=>'USER','DRIVER'=>'DRIVER'];

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
$stmt->execute([$id]);
$user = $stmt->fetch();
if(!$user){ http_response_code(404); echo "ไม่พบผู้ใช้"; exit; }

$msg = '';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? $user['role'];
    $line = trim($_POST['line_user_id'] ?? '');
    $pwd  = $_POST['password'] ?? '';

    try {
        if($pwd!==''){
            $hash = password_hash($pwd, PASSWORD_BCRYPT);
            $upd = $pdo->prepare("UPDATE users SET name=?, email=?, role=?, line_user_id=?, password_hash=? WHERE id=?");
            $upd->execute([$name,$email,$role,$line ?: null,$hash,$id]);
        } else {
            $upd = $pdo->prepare("UPDATE users SET name=?, email=?, role=?, line_user_id=? WHERE id=?");
            $upd->execute([$name,$email,$role,$line ?: null,$id]);
        }
        $msg = 'บันทึกการแก้ไขเรียบร้อย';
        // refresh object
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id=?"); $stmt->execute([$id]); $user = $stmt->fetch();
    } catch (Exception $e){
        $msg = 'บันทึกไม่สำเร็จ: '.$e->getMessage();
    }
}
?>
<?php render_header('แก้ไขผู้ใช้งาน • Fleet'); ?>
  <?php if($msg): ?><div class="flash"><?=h($msg)?></div><?php endif; ?>
  <div class="card">
    <h2>แก้ไขผู้ใช้ #<?=h($user['id'])?></h2>
    <form method="post">
      <?php echo form_brand_badge(); ?>
      <label>ชื่อ - สกุล</label><input name="name" value="<?=h($user['name'])?>" required>
      <label>อีเมล</label><input name="email" type="email" value="<?=h($user['email'])?>" required>
      <label>บทบาท</label>
      <select name="role" required>
        <?php foreach($roles as $k=>$v): ?>
          <option value="<?=$k?>" <?=$k===$user['role']?'selected':''?>><?=$v?></option>
        <?php endforeach; ?>
      </select>
      <label>LINE User ID</label><input name="line_user_id" value="<?=h($user['line_user_id'])?>">
      <label>ตั้งรหัสผ่านใหม่ (ถ้าต้องการ)</label><input name="password" type="password" placeholder="เว้นว่างหากไม่ต้องการเปลี่ยน">
      <button>บันทึก</button>
      <a class="btn secondary" href="users_manage.php">กลับ</a>
    </form>
  </div>
<?php render_footer(); ?>