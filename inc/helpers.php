<?php
require_once __DIR__.'/config.php';


function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function redirect($path){
    $url = (strpos($path,'http')===0) ? $path : (BASE_URL . '/' . ltrim($path,'/'));
    header("Location: $url"); exit;
}

function require_login(){
    if (empty($_SESSION['user'])) { redirect('login.php'); }
    return $_SESSION['user'];
}

function require_role($roles){
    $u = require_login();
    $roles = is_array($roles) ? $roles : [$roles];
    if (!in_array($u['role'], $roles)) {
        http_response_code(403); echo "Forbidden"; exit;
    }
    return $u;
}

function money_thb($n){ return number_format($n,2); }
?>