<?php
require_once __DIR__ . '/security.php';
if (session_status() === PHP_SESSION_ACTIVE) return;
$directory = __DIR__ . '/storage/sessions';
if (!is_dir($directory)) mkdir($directory, 0700, true);
session_save_path($directory);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', '28800');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true, 'samesite' => 'Lax',
]);
session_start();
if (!empty($_SESSION['user_id']) && nitro_session_expired($_SESSION, time())) {
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['last_activity'] = time();
