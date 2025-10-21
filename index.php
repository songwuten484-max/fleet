<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>งานอาคารสถานที่และยานพาหนะ • FBA มจพ.ระยอง</title>
  <style>
    :root{
      --fba-blue:#0B5ED7;
      --fba-blue-ink:#0A3F9C;
      --ink:#0f172a;
      --muted:#64748b;
      --card:#ffffff;
      --bg:#f6f8fb;
      --radius:16px;
    }
    *{box-sizing:border-box}
    html,body{height:100%}
    body{
      margin:0;
      background:var(--bg);
      color:var(--ink);
      font:16px/1.6 ui-sans-serif,system-ui,-apple-system,"Segoe UI","Sarabun",sans-serif;
      display:grid;
      place-items:center;
      padding:24px;
    }
    .card{
      width:min(720px,100%);
      background:var(--card);
      border-radius:var(--radius);
      padding:36px 28px;
      box-shadow:0 6px 24px rgba(15,23,42,.06);
      text-align:center;
    }
    /* --- ช่องโลโก้เว็บ & โลโก้คณะ --- */
    .logos{
      display:flex; gap:16px; justify-content:center; align-items:center;
      margin-bottom:18px; flex-wrap:wrap;
    }
    .logo-slot{
      width:160px; height:56px; border:1px dashed #d8dee9; border-radius:12px;
      display:grid; place-items:center; padding:6px; background:#fff;
    }
    .logo-slot img{max-height:44px; max-width:140px; object-fit:contain; display:block}
    .logo-hint{font-size:.8rem; color:var(--muted)}
    h1{font-size:1.35rem;margin:6px 0 2px}
    p.sub{margin:0 0 22px;color:var(--muted);font-size:.95rem}

    /* --- ปุ่มระบบ: เรียงคนละบรรทัดเสมอ + มีโลโก้ในปุ่ม --- */
    .actions{display:flex; flex-direction:column; gap:12px; margin-top:10px}
    .btn{
      width:100%;
      padding:14px 18px; border-radius:12px; text-decoration:none;
      font-weight:600; letter-spacing:.2px;
      border:1px solid #e6eaf2; color:var(--ink); background:#fff;
      transition:transform .08s ease, border-color .15s ease, color .15s ease, background .15s ease;
      display:flex; align-items:center; justify-content:center; gap:12px;   /* สำคัญ: จัดโลโก้+ข้อความ */
      text-align:center;
    }
    .btn:hover{transform:translateY(-1px); border-color:#d7dde8}
    .btn.primary{background:var(--fba-blue); color:#fff; border-color:transparent}
    .btn.primary:hover{background:var(--fba-blue-ink)}

    .sys-logo{
      width:60px; height:60px; object-fit:contain; display:block;
      border-radius:8px; background:transparent; /* โลโก้โปร่งใสจะกลืนกับพื้นหลัง */
    }
    .label{display:inline-block}

    .note{margin-top:22px; color:var(--muted); font-size:.9rem}
  </style>
</head>
<body>
  <main class="card" role="main" aria-labelledby="title">
    <!-- ใส่โลโก้เว็บ & โลโก้คณะ (แทนที่ src ตามจริง) -->
    <div  title="โลโก้คณะ">
        <img src="FBA_fleet/assets/logofba.png" alt="โลโก้คณะบริหารธุรกิจ มจพ. ระยอง" width="170" height="150">

      </div>

    <h1 id="title">งานอาคารสถานที่และยานพาหนะ</h1>
    <p class="sub">คณะบริหารธุรกิจ มหาวิทยาลัยเทคโนโลยีพระจอมเกล้าพระนครเหนือ วิทยาเขตระยอง</p>

    <nav class="actions" aria-label="เลือกระบบ">
      <a class="btn" href="FBA_fleet/public/">
        <img class="sys-logo" src="FBA_fleet/assets/logo.png" alt="โลโก้ระบบจองรถคณะบริหารธุรกิจ">
        <span class="label">ระบบจองรถคณะบริหารธุรกิจ</span>
      </a>
      <a class="btn" href="Room-system/">
        <img class="sys-logo" src="Room-system/uploads/logo.png" alt="โลโก้ระบบจัดการห้องเรียนคณะบริหารธุรกิจ">
        <span class="label">ระบบจัดการห้องเรียนคณะบริหารธุรกิจ</span>
      </a>
      <a class="btn" href="http://10.50.51.82/fba-repair/login.php">
        <img class="sys-logo" src="FBA_fleet/assets/logofba.png" alt="โลโก้ระบบจัดการห้องเรียนคณะบริหารธุรกิจ">
        <span class="label">ระบบแจ้งซ่อมออนไลน์</span>
      </a>

      <!-- ถ้าจะเพิ่ม “ระบบแจ้งซ่อม” ภายหลัง ใช้รูปแบบเดียวกันนี้ -->
      <!--
      <a class="btn" href="maintenance/">
        <img class="sys-logo" src="assets/sys-maintenance.png" alt="โลโก้ระบบแจ้งซ่อม">
        <span class="label">ระบบแจ้งซ่อม</span>
      </a>
      -->
    </nav>

    <div class="note">© Faculty of Business Administration, KMUTNB Rayong</div>
  </main>
</body>
</html>
