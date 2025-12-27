<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$databaseFile = __DIR__ . '/database.php';

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

if (is_file($databaseFile)) {
    $dbConfig = require $databaseFile;
    if (is_array($dbConfig)) {
        $db = array_merge($db, $dbConfig);
    }
}

return [
    'app' => [
        'base_url' => '',
    ],
    'db' => $db,
    'install' => [
        'lock' => $root . '/install.lock',
        'database_file' => $databaseFile,
        'password_file' => __DIR__ . '/install.pw',
    ],
    'session' => [
        'name' => 'lexnova_session',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ],
    'security' => require __DIR__ . '/security.php',
];
