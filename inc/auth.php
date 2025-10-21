<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/helpers.php';

function attempt_login($email, $password){
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if ($u && password_verify($password, $u['password_hash'])) {
        $_SESSION['user'] = $u;
        return true;
    }
    return false;
}
?>