<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = app_config();
    $db = $config['db'];

    if (!$db['dsn']) {
        throw new RuntimeException('Database is not configured.');
    }

    $pdo = new PDO($db['dsn'], $db['user'], $db['password'], $db['options']);

    return $pdo;
}
