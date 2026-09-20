<?php

declare(strict_types=1);

// Emit the shortest round-trippable representation for floating-point values.
// This prevents host-level serialize_precision settings from exposing binary
// floating-point artifacts in JSON payloads (for example 79.900000000000005...).
ini_set('serialize_precision', '-1');

use SyncBridge\Support\Env;

define('SYNCBRIDGE_ROOT', __DIR__);

$composerAutoload = SYNCBRIDGE_ROOT . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require $composerAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'SyncBridge\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $path = SYNCBRIDGE_ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require $path;
        }
    });
}

Env::load(SYNCBRIDGE_ROOT . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'UTC'));

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
