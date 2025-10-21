<?php
// public/fuel_fill_new.php
// หน้าบันทึกรายการเติมน้ำมัน เขียนลง TB fuel_card_transactions + แนบสลิป/ใบเสร็จ
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

$u   = require_role(['ADMIN','APPROVER_L1','APPROVER_L2','DRIVER']);
$pdo = db();

$flash = ''; $error = '';

// ------- ดึงข้อมูลรถ + บัตรน้ำมัน (active) -------
$vehicles = $pdo->query("SELECT id, plate_no, brand_model, current_odometer FROM vehicles WHERE active=1 ORDER BY plate_no ASC")->fetchAll();

$cardsStmt = $pdo->query("
  SELECT fc.id, fc.vehicle_id, fc.card_number, fc.provider, fc.is_primary, v.plate_no
  FROM fuel_cards fc
  JOIN vehicles v ON v.id = fc.vehicle_id
  WHERE fc.active = 1
  ORDER BY v.plate_no ASC, fc.is_primary DESC, fc.card_number ASC
");
$fuel_cards = $cardsStmt->fetchAll();

/* ===== helper: อัปโหลดไฟล์สลิปใบเสร็จ =====
   - รองรับ: jpg, jpeg, png, gif, pdf
   - จำกัดขนาด ~ 5 MB
   - เก็บไฟล์ไว้ใน public/uploads/fuel_slips/
   - คืนพาธแบบ relative (เช่น 'uploads/fuel_slips/abc123.jpg') หรือ null ถ้าไม่อัปโหลด
*/
function handle_receipt_upload(string $field = 'receipt_file'): ?string {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null; // ไม่ได้อัปโหลด
    }

    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('อัปโหลดไฟล์ไม่สำเร็จ (error '.$f['error'].')');
    }

    // จำกัดขนาดไฟล์ (5 MB)
    $maxBytes = 5 * 1024 * 1024;
    if ($f['size'] > $maxBytes) {
        throw new Exception('ไฟล์ใหญ่เกินไป (จำกัด 5 MB)');
    }

    // ตรวจ MIME จริง
    $fi = new finfo(FILEINFO_MIME_TYPE);
    $mime = $fi->file($f['tmp_name']) ?: '';
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'application/pdf' => 'pdf',
    ];
    if (!isset($allowed[$mime])) {
        throw new Exception('ชนิดไฟล์ไม่รองรับ (อนุญาต: JPG, PNG, GIF, PDF)');
    }

    // สร้างโฟลเดอร์ปลายทาง (ใต้ public/)
    $destDir = __DIR__ . '/uploads/fuel_slips';
    if (!is_dir($destDir)) {
        if (!@mkdir($destDir, 0775, true) && !is_dir($destDir)) {
            throw new Exception('ไม่สามารถสร้างโฟลเดอร์อัปโหลด: uploads/fuel_slips');
        }
    }

    // สร้างชื่อไฟล์ใหม่กันชน/ทับ (ไอดีผู้ใช้ + เวลา + rand)
    $ext = $allowed[$mime];
    $basename = 'slip_' . time() . '_' . mt_rand(1000,9999) . '_' . (int)($_SESSION['user']['id'] ?? 0) . '.' . $ext;
    $destPath = $destDir . '/' . $basename;

    if (!@move_uploaded_file($f['tmp_name'], $destPath)) {
        throw new Exception('ย้ายไฟล์อัปโหลดไม่สำเร็จ');
    }

    // คืนพาธแบบ relative ที่เบราเซอร์เข้าถึงได้
    return 'uploads/fuel_slips/' . $basename;
}

// ------- เมื่อ submit ฟอร์ม -------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vehicle_id   = (int)($_POST['vehicle_id'] ?? 0);
    $card_id      = ($_POST['card_id'] ?? '') !== '' ? (int)$_POST['card_id'] : null;
    $tx_date      = trim($_POST['tx_date'] ?? '');
    $station      = trim($_POST['station'] ?? '');
    $product      = trim($_POST['product'] ?? 'ดีเซล'); // เบนซิน/แก๊สโซฮอล์/ดีเซล แล้วแต่ตั้งค่า
    $liters       = trim($_POST['liters'] ?? '');
    $amount       = trim($_POST['amount'] ?? '');
    $ppL          = trim($_POST['price_per_liter'] ?? '');
    $odometer     = trim($_POST['odometer'] ?? '');
    $receipt_no   = trim($_POST['receipt_no'] ?? '');
    $notes        = trim($_POST['notes'] ?? '');

    try {
        // ---- validate เบื้องต้น ----
        if (!$vehicle_id) throw new Exception('กรุณาเลือกรถ');
        if ($tx_date === '') throw new Exception('กรุณาเลือกวันที่');
        if ($liters === '' && $amount === '') {
            throw new Exception('กรุณากรอกอย่างน้อย ปริมาณ (ลิตร) หรือ ยอดเงิน (บาท)');
        }

        // แปลงตัวเลข
        $liters_f = ($liters !== '') ? (float)$liters : null;
        $amount_f = ($amount !== '') ? (float)$amount : null;
        $ppL_f    = ($ppL   !== '') ? (float)$ppL : null;
        $odo_i    = ($odometer !== '') ? (int)$odometer : null;

        // คำนวณราคา/ลิตรอัตโนมัติถ้ายังว่าง
        if ($ppL_f === null && $liters_f && $amount_f) {
            $ppL_f = ($liters_f > 0 ? $amount_f / $liters_f : null);
        }
        // คำนวณ amount อัตโนมัติ ถ้ายังว่าง
        if ($amount_f === null && $liters_f && $ppL_f) {
            $amount_f = $liters_f * $ppL_f;
        }

        // ดึงหมายเลขบัตร (เผื่อเก็บซ้ำซ้อนสำหรับรายงาน)
        $card_number = null;
        if ($card_id) {
            $q = $pdo->prepare("SELECT card_number FROM fuel_cards WHERE id=? AND active=1 LIMIT 1");
            $q->execute([$card_id]);
            $card_number = ($row = $q->fetch()) ? $row['card_number'] : null;
        }

        // อัปโหลดไฟล์สลิป (อาจเป็น null ถ้าไม่แนบ)
        $receipt_file = handle_receipt_upload('receipt_file');

        // บันทึก
        $ins = $pdo->prepare("
          INSERT INTO fuel_card_transactions
          (vehicle_id, card_id, card_number, tx_date, station, product,
           liters, price_per_liter, amount, odometer, driver_id, receipt_no, notes, receipt_file,
           created_at, updated_at)
          VALUES
          (:vehicle_id, :card_id, :card_number, :tx_date, :station, :product,
           :liters, :price_per_liter, :amount, :odometer, :driver_id, :receipt_no, :notes, :receipt_file,
           NOW(), NOW())
        ");
        $ins->execute([
          ':vehicle_id'       => $vehicle_id,
          ':card_id'          => $card_id,
          ':card_number'      => $card_number,
          ':tx_date'          => $tx_date,
          ':station'          => $station !== '' ? $station : null,
          ':product'          => $product !== '' ? $product : null,
          ':liters'           => $liters_f,
          ':price_per_liter'  => $ppL_f,
          ':amount'           => $amount_f,
          ':odometer'         => $odo_i,
          ':driver_id'        => $u['id'],
          ':receipt_no'       => $receipt_no !== '' ? $receipt_no : null,
          ':notes'            => $notes !== '' ? $notes : null,
          ':receipt_file'     => $receipt_file,
        ]);

        // อัปเดตเลขไมล์รถ (ถ้ามี กรอก และ มากกว่าของเดิม)
        if ($odo_i !== null) {
            $uveh = $pdo->prepare("UPDATE vehicles SET current_odometer = GREATEST(IFNULL(current_odometer,0), :odo) WHERE id=:vid");
            $uveh->execute([':odo'=>$odo_i, ':vid'=>$vehicle_id]);
        }

        // เสร็จแล้วรีไดเรกต์กลับหน้าเดิมให้กดเพิ่มต่อได้
        redirect('fuel_fill_new.php?ok=1');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

render_header('บันทึกรายการเติมน้ำมัน • Fleet');
?>
<?php if(isset($_GET['ok'])): ?>
  <div class="flash">บันทึกรายการเติมน้ำมันเรียบร้อย</div>
<?php endif; ?>
<?php if($error): ?>
  <div class="flash" style="background:#ffecec;border-color:#ffcccc;color:#a33;"><?= h($error) ?></div>
<?php endif; ?>

<div class="card">
  <h2>บันทึกรายการเติมน้ำมัน</h2>
  <!-- ต้องใส่ enctype เพื่ออัปโหลดไฟล์ -->
  <form method="post" autocomplete="off" id="fuel-form" enctype="multipart/form-data">
    <?php echo function_exists('form_brand_badge') ? form_brand_badge() : ''; ?>

    <label>รถที่ใช้ *</label>
    <select name="vehicle_id" id="vehicle_id" required>
      <option value="">— เลือกรถ —</option>
      <?php foreach($vehicles as $v): ?>
        <option value="<?= (int)$v['id'] ?>"
                data-odo="<?= (int)$v['current_odometer'] ?>">
          <?= h($v['plate_no'].' • '.$v['brand_model']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label>บัตรเติมน้ำมัน</label>
    <select name="card_id" id="card_id">
      <option value="">— เลือกบัตร (ถ้ามี) —</option>
      <?php foreach($fuel_cards as $c): ?>
        <option value="<?= (int)$c['id'] ?>"
                data-vehicle="<?= (int)$c['vehicle_id'] ?>">
          <?= h($c['plate_no']) ?> • <?= h($c['provider']) ?> • <?= h($c['card_number']) ?><?= $c['is_primary']?' • [หลัก]':'' ?>
        </option>
      <?php endforeach; ?>
    </select>

    <div style="display:flex; gap:12px; flex-wrap:wrap;">
      <div style="flex:1; min-width:220px;">
        <label>วันที่ทำรายการ *</label>
        <input type="date" name="tx_date" required value="<?= h(date('Y-m-d')) ?>">
      </div>
      <div style="flex:1; min-width:220px;">
        <label>สถานี/ปั๊ม</label>
        <input type="text" name="station" placeholder="เช่น PTT บางซื่อ">
      </div>
      <div style="flex:1; min-width:220px;">
        <label>ชนิดน้ำมัน</label>
        <input type="text" name="product" value="ดีเซล">
      </div>
    </div>

    <div style="display:flex; gap:12px; flex-wrap:wrap;">
      <div style="flex:1; min-width:160px;">
        <label>ปริมาณ (ลิตร)</label>
        <input type="number" step="0.001" name="liters" id="liters">
      </div>
      <div style="flex:1; min-width:160px;">
        <label>ราคา/ลิตร (บาท)</label>
        <input type="number" step="0.01" name="price_per_liter" id="price_per_liter">
      </div>
      <div style="flex:1; min-width:160px;">
        <label>ยอดเงิน (บาท)</label>
        <input type="number" step="0.01" name="amount" id="amount">
      </div>
      <div style="flex:1; min-width:180px;">
        <label>เลขไมล์ ณ เวลานั้น</label>
        <input type="number" name="odometer" id="odometer" placeholder="">
      </div>
    </div>

    <div style="display:flex; gap:12px; flex-wrap:wrap;">
      <div style="flex:1; min-width:220px;">
        <label>เลขที่ใบเสร็จ</label>
        <input type="text" name="receipt_no" placeholder="เช่น INV/RCPT">
      </div>
      <div style="flex:2; min-width:320px;">
        <label>หมายเหตุ</label>
        <input type="text" name="notes" placeholder="ระบุเพิ่มเติม (ถ้ามี)">
      </div>
    </div>

    <div style="margin-top:10px;">
      <label>แนบสลิป/ใบเสร็จ (JPG/PNG/GIF/PDF, สูงสุด 5MB)</label>
      <input type="file" name="receipt_file" id="receipt_file" accept=".jpg,.jpeg,.png,.gif,.pdf,image/*,application/pdf">
      <div id="preview" style="margin-top:6px;"></div>
    </div>

    <div class="small" style="color:#666; margin-top:6px;">
      * หากเว้น “ราคา/ลิตร” ระบบจะคำนวณจาก <u>ยอดเงิน ÷ ลิตร</u> ให้โดยอัตโนมัติ (ถ้ากรอกครบ)
    </div>

    <div style="margin-top:10px;">
      <button type="submit">บันทึก</button>
      <a class="btn secondary" href="dashboard.php">กลับ</a>
    </div>
  </form>
</div>

<script>
// กรองบัตรตามรถที่เลือก + ใส่ placeholder ไมล์
const selVehicle = document.getElementById('vehicle_id');
const selCard    = document.getElementById('card_id');
const odoInput   = document.getElementById('odometer');
const liters     = document.getElementById('liters');
const ppl        = document.getElementById('price_per_liter');
const amount     = document.getElementById('amount');
const fileInput  = document.getElementById('receipt_file');
const preview    = document.getElementById('preview');

// ซ่อนตัวเลือกที่ไม่ตรง vehicle
function filterCards() {
  const vid = parseInt(selVehicle.value || '0', 10);
  for (const opt of selCard.options) {
    if (!opt.value) { opt.hidden = false; continue; } // แถว default
    const v = parseInt(opt.getAttribute('data-vehicle') || '0', 10);
    opt.hidden = (vid && v !== vid);
  }
  // ถ้าตัวเลือกที่เลือกไว้คนละรถ ให้รีเซ็ต
  if (selCard.selectedOptions.length) {
    const cur = selCard.selectedOptions[0];
    if (cur.hidden) selCard.value = '';
  }
}

selVehicle.addEventListener('change', ()=>{
  // กรองบัตร
  filterCards();

  // ใส่ placeholder ไมล์ปัจจุบัน
  const opt = selVehicle.selectedOptions[0];
  const odo = opt ? (opt.getAttribute('data-odo') || '') : '';
  odoInput.placeholder = odo ? ('เลขไมล์ปัจจุบัน: ' + odo) : '';
});
filterCards();

// ช่วยคำนวณอัตโนมัติพื้นฐาน
function recalc() {
  const L = parseFloat(liters.value);
  const P = parseFloat(ppl.value);
  const A = parseFloat(amount.value);
  if (!isNaN(L) && !isNaN(P) && (isNaN(A) || A===0)) {
    amount.value = (L * P).toFixed(2);
  } else if (!isNaN(L) && !isNaN(A) && (isNaN(P) || P===0)) {
    ppl.value = L > 0 ? (A / L).toFixed(2) : '';
  } else if (!isNaN(P) && !isNaN(A) && (isNaN(L) || L===0)) {
    liters.value = P > 0 ? (A / P).toFixed(2) : '';
  }
}
[liters, ppl, amount].forEach(el => el.addEventListener('change', recalc));

// พรีวิวเฉพาะรูปภาพ (ถ้าเป็น PDF จะโชว์ลิงก์)
fileInput.addEventListener('change', ()=>{
  preview.innerHTML = '';
  const file = fileInput.files && fileInput.files[0];
  if (!file) return;
  const type = file.type || '';
  if (type.startsWith('image/')) {
    const url = URL.createObjectURL(file);
    const img = document.createElement('img');
    img.src = url;
    img.style.maxWidth = '260px';
    img.style.maxHeight = '180px';
    img.style.border = '1px solid #ddd';
    img.style.padding = '4px';
    img.style.borderRadius = '6px';
    preview.appendChild(img);
  } else if (type === 'application/pdf') {
    const note = document.createElement('div');
    note.innerHTML = 'เลือกไฟล์ PDF แล้ว: <b>' + file.name + '</b>';
    preview.appendChild(note);
  } else {
    preview.textContent = 'ไฟล์ชนิดนี้ไม่รองรับพรีวิว';
  }
});
</script>

<?php render_footer(); ?>
