<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/helpers.php';

/* ---------- เมนูหลัก ---------- */
if (!function_exists('nav_item')) {
  function nav_item($file, $label) {
    $isActive = (basename($_SERVER['SCRIPT_NAME']) === $file) ? 'active' : '';
    echo '<a class="'.$isActive.'" href="'.BASE_URL.'/'.$file.'">'.$label.'</a>';
  }
}

if (!function_exists('nav_item_roles')) {
  function nav_item_roles($file, $label, $roles = null) {
    if ($roles) {
      if (empty($_SESSION['user']) || !in_array($_SESSION['user']['role'], $roles)) return;
    }
    nav_item($file, $label);
  }
}

/* helper สั้น ๆ เช็คบทบาท */
if (!function_exists('has_role')) {
  function has_role($roles) {
    if (empty($_SESSION['user']['role'])) return false;
    return in_array($_SESSION['user']['role'], (array)$roles);
  }
}

/* ---------- ส่วนหัวของหน้า ---------- */
function render_header($title, $extra_head = ''){
  $userInitial = '';
  if (!empty($_SESSION['user']['name'])) {
    $userInitial = mb_substr(trim($_SESSION['user']['name']), 0, 1);
  }
?><!doctype html><html lang="th"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h($title)?></title>
<link rel="stylesheet" href="../assets/style.css">
<style>
/* user menu (มุมขวา) */
.user-menu { position: relative; display: inline-block; }
.user-menu button {
  background: none; border: none; color: var(--fba-blue-ink,#0A3F9C);
  font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px;
}
.user-avatar {
  width: 26px; height: 26px; border-radius: 50%;
  background: var(--fba-blue,#0B5ED7); color:#fff; font-weight:600; font-size:13px;
  display:flex; align-items:center; justify-content:center;
}
.user-dropdown {
  display:none; position:absolute; right:0; top:100%;
  background:#fff; border:1px solid #ddd; border-radius:8px;
  box-shadow:0 4px 10px rgba(0,0,0,.1); min-width:180px; z-index:100;
}
.user-dropdown a { display:block; padding:8px 12px; color:#333; text-decoration:none; }
.user-dropdown a:hover { background:#f6f8fb; color:var(--fba-blue-ink,#0A3F9C); }

/* nav dropdown (เมนูประเมินคนขับรถ) */
.nav-menu { position: relative; display:inline-block; }
.nav-menu > button {
  background:none; border:none; cursor:pointer; font-weight:600; color:#333;
  padding:6px 8px; border-radius:8px;
}
.nav-menu > button:hover { background:#f6f8fb; }
.nav-dropdown {
  display:none; position:absolute; left:0; top:100%;
  background:#fff; border:1px solid #ddd; border-radius:8px;
  box-shadow:0 4px 10px rgba(0,0,0,.08); min-width:220px; z-index:90;
}
.nav-dropdown a { display:block; padding:8px 12px; color:#333; text-decoration:none; }
.nav-dropdown a:hover { background:#f6f8fb; color:var(--fba-blue-ink,#0A3F9C); }
</style>
<?= $extra_head ?>
</head>
<body class="container">
  <header class="brand brand--header">
    <img class="brand__logo" src="<?=BRAND_LOGO_REL?>" alt="<?=h(BRAND_FULL)?>" />
    <div class="brand__text">
      <div class="brand__title">Fleet • ระบบจองรถ</div>
      <div class="brand__subtitle"><?=h(BRAND_FULL)?></div>
    </div>
  </header>

  <?php if (!empty($_SESSION['user'])): ?>
  <nav class="main-nav">
    <?php 
      nav_item_roles("dashboard.php","หน้าแรก");
      nav_item_roles("vehicles.php","รถยนต์", ["ADMIN","APPROVER_L1","APPROVER_L2"]);
      nav_item_roles("bookings_create.php","ขอใช้รถ");
      nav_item_roles("bookings_my.php","การจอง");
      nav_item_roles("bookings_admin.php","อนุมัติ", ["ADMIN","APPROVER_L1"]);
      nav_item_roles("vehicle_inspections.php","ตรวจสภาพรถ", ["DRIVER"]);
      nav_item_roles("trips_start.php","เริ่มทริป");
      nav_item_roles("trips_end.php","สิ้นสุดทริป");
      nav_item_roles("fuel_fill_new.php","เติมน้ำมัน", ["ADMIN","APPROVER_L1","APPROVER_L2","DRIVER"]);
      nav_item_roles("fuel_fills.php","รายการน้ำมัน", ["ADMIN","APPROVER_L1","APPROVER_L2","DRIVER"]);
      nav_item_roles("map_live.php","แผนที่",["ADMIN","APPROVER_L1"]); 
      nav_item_roles("report_monthly.php","รายงาน", ["ADMIN","APPROVER_L1","APPROVER_L2"]);
      nav_item_roles("users_manage.php","ผู้ใช้งาน", ["ADMIN"]);
    ?>

    <!-- ปุ่มดรอปดาวน์: ประเมินคนขับรถ -->
    <div class="nav-menu">
      <button id="evalBtn" type="button" aria-haspopup="true" aria-expanded="false">
        ประเมิน⌄
      </button>
      <div class="nav-dropdown" id="evalDropdown">
        <a href="<?=BASE_URL?>/driver_review.php">ประเมินคนขับรถ</a>
        <?php if (has_role(['ADMIN'])): ?>
          <a href="<?=BASE_URL?>/driver_review_report.php">สรุปแบบประเมิน</a>
        <?php endif; ?>
      </div>
    </div>

    <span class="spacer"></span>

    <!-- เมนูผู้ใช้ (โปรไฟล์ + ชื่อ) -->
    <div class="user-menu">
      <button id="userBtn" type="button" aria-haspopup="true" aria-expanded="false">
        <div class="user-avatar"><?=h($userInitial ?: 'U')?></div>
        <?=h($_SESSION['user']['name'] ?? 'ผู้ใช้งาน')?> ⌄
      </button>
      <div class="user-dropdown" id="userDropdown">
        <a href="<?=BASE_URL?>/profile_line.php">เชื่อมต่อ LINE OA</a>
        <a href="<?=BASE_URL?>/logout.php">ออกจากระบบ</a>
      </div>
    </div>
  </nav>

  <script>
    /* Dropdown: ผู้ใช้ */
    const userBtn = document.getElementById('userBtn');
    const userDropdown = document.getElementById('userDropdown');

    function closeUserMenu() {
      userDropdown.style.display = 'none';
      userBtn.setAttribute('aria-expanded', 'false');
    }
    function toggleUserMenu() {
      const open = userDropdown.style.display === 'block';
      userDropdown.style.display = open ? 'none' : 'block';
      userBtn.setAttribute('aria-expanded', open ? 'false' : 'true');
    }

    /* Dropdown: ประเมินคนขับรถ */
    const evalBtn = document.getElementById('evalBtn');
    const evalDropdown = document.getElementById('evalDropdown');

    function closeEvalMenu() {
      evalDropdown.style.display = 'none';
      evalBtn?.setAttribute('aria-expanded', 'false');
    }
    function toggleEvalMenu() {
      const open = evalDropdown.style.display === 'block';
      evalDropdown.style.display = open ? 'none' : 'block';
      evalBtn?.setAttribute('aria-expanded', open ? 'false' : 'true');
    }

    document.addEventListener('click', (e)=>{
      // toggle ของแต่ละปุ่ม
      if (userBtn && userBtn.contains(e.target)) {
        toggleUserMenu();
      } else if (evalBtn && evalBtn.contains(e.target)) {
        toggleEvalMenu();
      } else {
        // คลิกนอก → ปิดทั้งสองเมนู
        if (!userDropdown.contains(e.target)) closeUserMenu();
        if (!evalDropdown.contains(e.target)) closeEvalMenu();
      }
    });

    document.addEventListener('keydown', (e)=>{
      if (e.key === 'Escape') { closeUserMenu(); closeEvalMenu(); }
    });
  </script>
  <?php endif; ?>
<?php
}

/* ---------- ส่วนท้ายของหน้า ---------- */
function render_footer(){
?>
  <footer class="footer">
    <small>&copy; <?=date('Y')?> <?=h(BRAND_FULL)?> • All rights reserved.</small>
  </footer>
</body></html>
<?php
}

/* ---------- ฟังก์ชันแสดงโลโก้คณะ ---------- */
function form_brand_badge(){
  return '<div class="form-brand"><img src="'.BRAND_LOGO_REL.'" alt="'.h(BRAND_FULL).'" /><div><div class="fb">'.h(FACULTY_NAME).'</div><div class="uni">'.h(UNIVERSITY_NAME).'</div></div></div>';
}
?>
