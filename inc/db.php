<?php
/**
 * Database connector (PDO, MySQL)
 * - Reads from constants DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS if defined in inc/config.php
 * - Otherwise falls back to environment variables, then to sensible local defaults
 */
if (!function_exists('db')) {
  function db(){
    static $pdo = null;
    if ($pdo) return $pdo;

    // Ensure global config is loaded first (may define DB_* constants)
    $cfg = __DIR__ . '/config.php';
    if (file_exists($cfg)) require_once $cfg;

    $host = defined('DB_HOST') ? DB_HOST : (getenv('DB_HOST') ?: 'localhost');
    $port = defined('DB_PORT') ? DB_PORT : (getenv('DB_PORT') ?: '3306');
    $name = defined('DB_NAME') ? DB_NAME : (getenv('DB_NAME') ?: 'fleetdb');
    $user = defined('DB_USER') ? DB_USER : (getenv('DB_USER') ?: 'roombookingfba');
    $pass = defined('DB_PASS') ? DB_PASS : (getenv('DB_PASS') ?: 'gnH!#987*');
    
    


    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $opts = [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    try {
      $pdo = new PDO($dsn, $user, $pass, $opts);
    } catch (Throwable $e) {
      // Friendlier error with guidance
      http_response_code(500);
      echo "<h1>Database connection failed</h1>";
      echo "<p>" . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</p>";
      echo "<pre>DSN: " . htmlspecialchars($dsn, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\nUser: " . htmlspecialchars($user, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</pre>";
      exit;
    }
    

    return $pdo;
  }
}
