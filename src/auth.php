<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function start_app_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $config = app_config();
    $session = $config['session'];

    session_name($session['name']);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => (bool) $session['secure'],
        'httponly' => (bool) $session['httponly'],
        'samesite' => $session['samesite'],
    ]);

    session_start();
}

function is_admin(): bool
{
    return isset($_SESSION['user_id'], $_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = (string) $user['username'];
    $_SESSION['role'] = (string) $user['role'];
}

function logout_user(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
}

function find_user_by_username(string $username): ?array
{
    $stmt = db()->prepare('SELECT id, username, password_hash, role FROM users WHERE username = :username');
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function verify_credentials(string $username, string $password): ?array
{
    $user = find_user_by_username($username);

    if (!$user) {
        return null;
    }

    if (!password_verify($password, $user['password_hash'])) {
        return null;
    }

    return $user;
}
