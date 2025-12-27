<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

function list_users(): array
{
    $stmt = db()->query('SELECT id, username, role, created_at FROM users ORDER BY username ASC');
    return $stmt->fetchAll();
}

function get_user_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT id, username, role, created_at FROM users WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function create_user(string $username, string $password, string $role = 'admin'): int
{
    $config = app_config();
    $security = $config['security']['password'];

    $hash = password_hash($password, $security['algo'], $security['options']);
    if ($hash === false) {
        throw new RuntimeException('Password hashing failed.');
    }

    $stmt = db()->prepare('INSERT INTO users (username, password_hash, role, created_at) VALUES (:username, :hash, :role, :created_at)');
    $stmt->execute([
        ':username' => $username,
        ':hash' => $hash,
        ':role' => $role,
        ':created_at' => date('Y-m-d H:i:s'),
    ]);

    return (int) db()->lastInsertId();
}

function update_user_role(int $id, string $role): void
{
    $stmt = db()->prepare('UPDATE users SET role = :role WHERE id = :id');
    $stmt->execute([
        ':id' => $id,
        ':role' => $role,
    ]);
}

function update_user_password(int $id, string $password): void
{
    $config = app_config();
    $security = $config['security']['password'];

    $hash = password_hash($password, $security['algo'], $security['options']);
    if ($hash === false) {
        throw new RuntimeException('Password hashing failed.');
    }

    $stmt = db()->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
    $stmt->execute([
        ':id' => $id,
        ':hash' => $hash,
    ]);
}
