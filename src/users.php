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

/**
 * Validates a plain-text password against the configured password policy.
 * Returns an error message string on violation, or null when the password is valid.
 */
function validate_password(string $password): ?string
{
    $config = app_config();
    $policy = $config['security']['password_policy'];

    $min = max(8, (int) ($policy['min_length'] ?? 16));
    $max = min(256, max($min, (int) ($policy['max_length'] ?? 256)));
    $asciiOnly = (bool) ($policy['ascii_only'] ?? true);

    $len = strlen($password);

    if ($len < $min) {
        return "Password must be at least {$min} characters long.";
    }

    if ($len > $max) {
        return "Password must not exceed {$max} characters.";
    }

    if ($asciiOnly && !preg_match('/^[\x20-\x7E]+$/', $password)) {
        return 'Password may only contain standard printable ASCII characters '
             . '(letters, digits and keyboard symbols like !, @, #, $, …). '
             . 'Accented, non-Latin or special Unicode characters are not allowed '
             . 'to prevent keyboard-layout lockouts.';
    }

    return null;
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
