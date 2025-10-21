<?php
// public/approvals_l1.php
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

require_once __DIR__.'/../inc/config.php';
require_once __DIR__.'/../inc/layout.php';
require_once __DIR__.'/../inc/helpers.php';
require_once __DIR__.'/../inc/db.php';
require_once __DIR__.'/../inc/line.php';

$u = require_role(['ADMIN','APPROVER_L1']); // อนุมัติโดย หัวหน้าสำนักงานคณบดี (ใช้บทบาท APPROVER_L1)
$pdo = db();

/* helper: ทำลิงก์ไฟล์แนบให้ปลอดภัย */
function render_request_file_link(?string $path): string {
  if (!$path) return '<span style="color:#666">ไม่มี</span>';
  // ป้องกันกรณีพาธนอก public
  $safe = htmlspecialchars($path, ENT_QUOTES, 'UTF-8');
  $name = htmlspecialchars(basename($path), ENT_QUOTES, 'UTF-8');
  // เปิดแท็บใหม่ + download attribute เพื่อให้เบราว์เซอร์ดาวน์โหลดได้
  return '<a class="btn small" href="'.$safe.'" target="_blank" rel="noopener" download="'.$name.'">ไฟล์ต้นเรื่อง</a>';
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id=?");
    $stmt->execute([$id]); 
    $b = $stmt->fetch();
    if (!$b) { die('not found'); }

    if ($action==='approve') {
        // อนุมัติขั้นเดียว
        $pdo->prepare("UPDATE bookings 
                          SET status='APPROVED', 
                              approval_stage=1, 
                              approval_required=1, 
                              approver_l1_id=?, 
                              approver_l1_at=NOW() 
                        WHERE id=?")
            ->execute([$u['id'],$id]);

        // แจ้งผู้ขอผ่าน LINE (ถ้ามี)
        $rq = $pdo->prepare("SELECT u.line_user_id FROM users u WHERE u.id=?");
        $rq->execute([$b['user_id']]); 
        $usr = $rq->fetch();
        if ($usr && $usr['line_user_id']) {
          line_push($usr['line_user_id'], [line_text("คำขอใช้รถ #$id ได้รับอนุมัติแล้ว (หัวหน้าสำนักงานคณบดี)")]);
        }
    } elseif ($action==='reject') {
        $pdo->prepare("UPDATE bookings SET status='REJECTED' WHERE id=?")->execute([$id]);

        // แจ้งผู้ขอผ่าน LINE (ถ้ามี)
        $rq = $pdo->prepare("SELECT u.line_user_id FROM users u WHERE u.id=?");
        $rq->execute([$b['user_id']]); 
        $usr = $rq->fetch();
        if ($usr && $usr['line_user_id']) {
          line_push($usr['line_user_id'], [line_text("คำขอใช้รถ #$id ถูกปฏิเสธ")]);
        }
    }

    // กลับมาหน้าเดิม (กันรีเฟรชซ้ำ)
    header('Location: approvals_l1.php');
    exit;
}

// ดึงรายการรวมไฟล์แนบ
$rows = $pdo->query("
  SELECT b.*, v.plate_no, v.brand_model, u.name as uname 
  FROM bookings b 
  JOIN vehicles v ON v.id=b.vehicle_id 
  JOIN users u ON u.id=b.user_id
  ORDER BY b.id DESC
")->fetchAll();

render_header('อนุมัติการจอง (หัวหน้าสำนักงานคณบดี) • Fleet');
?>
  <div class="card">
    <h2>อนุมัติการจอง (หัวหน้าสำนักงานคณบดี)</h2>
    <table>
      <tr>
        <th>#</th>
        <th>ผู้ขอ</th>
        <th>รถ</th>
        <th>ช่วงเวลา</th>
        <th>ไฟล์ต้นเรื่อง</th>
        <th>สถานะ</th>
        <th>การจัดการ</th>
      </tr>
      <?php foreach($rows as $r): ?>
      <tr>
        <td><?=h($r['id'])?></td>
        <td><?=h($r['uname'])?></td>
        <td><?=h($r['plate_no'].' • '.$r['brand_model'])?></td>
        <td><?=h($r['start_datetime'].' → '.$r['end_datetime'])?></td>
        <td><?=render_request_file_link($r['request_file'] ?? null)?></td>
        <td>
          <span class="badge <?=strtolower($r['status'])?>"><?=h($r['status'])?></span>
        </td>
        <td>
          <?php if($r['status']==='PENDING'): ?>
            <form method="post" style="display:inline">
              <input type="hidden" name="id" value="<?=$r['id']?>">
              <button name="action" value="approve">อนุมัติ</button>
            </form>
          <?php endif; ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="id" value="<?=$r['id']?>">
            <button class="secondary" name="action" value="reject">ปฏิเสธ</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
<?php render_footer(); ?>
