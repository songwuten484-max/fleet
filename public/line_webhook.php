<?php

require_once __DIR__.'/../inc/config.php';
require_once __DIR__.'/../inc/db.php';
require_once __DIR__.'/../inc/line.php';

// Raw body & signature from LINE
$raw = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';

// Allow skipping signature verification in dev (either ENV or constant)
$skip = (getenv('LINE_SKIP_SIGNATURE') === '1') || (defined('LINE_SKIP_SIGNATURE') && LINE_SKIP_SIGNATURE === true);

if (!$skip) {
  if (!line_verify_signature($raw, $sig)) {
    http_response_code(400);
    echo 'bad sig';
    // Uncomment for debug ONLY (write to PHP error log):
    // $calc = base64_encode(hash_hmac('sha256', $raw, LINE_CHANNEL_SECRET, true));
    // error_log("LINE bad sig: header=$sig calc=$calc secret_prefix=" . substr(LINE_CHANNEL_SECRET,0,6));
    exit;
  }
}

// Accept GET (for quick health check) or empty body gracefully
if ($_SERVER['REQUEST_METHOD'] === 'GET' || !$raw) { echo 'ok'; exit; }

$data = json_decode($raw, true);
if (!$data) { echo 'ok'; exit; }

$pdo = db();

foreach (($data['events'] ?? []) as $ev) {
  $type = $ev['type'] ?? '';
  $reply = $ev['replyToken'] ?? null;
  $source = $ev['source'] ?? [];
  $uid = $source['userId'] ?? null;

  // Greet on follow
  if ($type === 'follow') {
    if ($reply) line_reply($reply, [line_text("สวัสดีครับ 🙌\nพิมพ์: LINK <โค้ด> เพื่อเชื่อมบัญชีเว็บกับ LINE OA\nตัวอย่าง: LINK ABC123\nพิมพ์ UNLINK เพื่อยกเลิกการเชื่อม")]);
    continue;
  }

  if ($type === 'message' && ($ev['message']['type'] ?? '') === 'text') {
    $text = trim($ev['message']['text'] ?? '');

    // LINK <TOKEN> (token: 4..32, alnum or - ; allow colon or multi-space after LINK)
    if (preg_match('/^LINK[:\s]+([A-Za-z0-9-]{4,32})$/i', $text, $m)) {
      $token = strtoupper($m[1]);
      $stmt = $pdo->prepare("SELECT * FROM link_tokens WHERE token=? AND used_at IS NULL ORDER BY id DESC LIMIT 1");
      $stmt->execute([$token]);
      $row = $stmt->fetch();

      if ($row && $uid) {
        // Bind LINE userId -> users.id
        $pdo->prepare("UPDATE users SET line_user_id=? WHERE id=?")->execute([$uid, $row['user_id']]);
        $pdo->prepare("UPDATE link_tokens SET used_at=NOW(), line_user_id=? WHERE id=?")->execute([$uid, $row['id']]);
        if ($reply) line_reply($reply, [line_text("เชื่อมบัญชีเรียบร้อย ✅\nขอบคุณครับ")]);
      } else {
        if ($reply) line_reply($reply, [line_text("โค้ดไม่ถูกต้อง หรือถูกใช้ไปแล้ว ❌\nให้สร้างโค้ดใหม่ที่หน้า เชื่อม LINE OA ในระบบเว็บ")]);
      }
      continue;
    }

    // UNLINK
    if (preg_match('/^UNLINK$/i', $text)) {
      if ($uid) {
        $pdo->prepare("UPDATE users SET line_user_id=NULL WHERE line_user_id=?")->execute([$uid]);
        if ($reply) line_reply($reply, [line_text("ยกเลิกการเชื่อมสำเร็จ ✅")]);
      } else if ($reply) {
        line_reply($reply, [line_text("ไม่พบ LINE User ID")]);
      }
      continue;
    }

    // Simple ping
    if (preg_match('/^PING$/i', $text)) {
      if ($reply) line_reply($reply, [line_text("PONG ✅")]);
      continue;
    }

    // Help fallback
    if ($reply) line_reply($reply, [line_text("พิมพ์: LINK <โค้ด> เพื่อเชื่อมบัญชี หรือ UNLINK เพื่อยกเลิก")]);
  }
}

echo 'ok';
