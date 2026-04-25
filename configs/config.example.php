<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$configFile = __DIR__ . '/config.php';

$db = [
    'dsn' => null,
    'user' => null,
    'password' => null,
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
];

return [
    'app' => [
        'base_url' => '',
    ],
    'db' => $db,
    'install' => [
        'lock' => $root . '/install/install.lock',
        'password_file' => $root . '/install/install.pw',
        'config_file' => $configFile,
    ],
    'session' => [
        'name' => 'lexnova_session',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ],
    'log' => [
        'path'  => $root . '/data/lexnova.log',
        'level' => 'info',
    ],
    'security' => require __DIR__ . '/security.php',
];
