<?php
require_once __DIR__.'/session_boot.php';

function current_user(): array|null {
  return $_SESSION['user'] ?? null;
}

// guard แบบไม่สร้างลูป
function require_login(): array {
  if (empty($_SESSION['user'])) {
    if (basename($_SERVER['PHP_SELF']) !== 'login.php') {
      header('Location: login.php');
      exit;
    }
    return [];
  }
  return $_SESSION['user'];
}
