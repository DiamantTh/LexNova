<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use LexNova\Service\AuthSessionService;
use Mezzio\Session\Session;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$db->executeStatement('CREATE TABLE users (
    id INTEGER PRIMARY KEY, role TEXT NOT NULL, activation_required INTEGER NOT NULL DEFAULT 0
)');
$db->executeStatement('CREATE TABLE user_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, session_hash CHAR(64) NOT NULL UNIQUE,
    auth_method VARCHAR(20) NOT NULL, auth_strength VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL, last_activity_at DATETIME NOT NULL,
    absolute_expires_at DATETIME NOT NULL, revoked_at DATETIME NULL
)');
$db->insert('users', ['id' => 1, 'role' => 'admin']);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$session = new Session(['user_id' => 1, 'username' => 'session-user', 'role' => 'admin'], 'session-test');
$service = new AuthSessionService($db, idleSeconds: 1800, absoluteSeconds: 43200);
$sessionId = $service->establish($session, 1, 'webauthn', 'uv');
$row = $db->fetchAssociative('SELECT auth_method, auth_strength, session_hash FROM user_sessions WHERE id = ?', [$sessionId]);
if (!$service->isValid($session, 1) || $row['auth_method'] !== 'webauthn' || $row['auth_strength'] !== 'uv'
    || !hash_equals(hash('sha256', session_id()), (string) $row['session_hash'])
) {
    throw new RuntimeException('Authenticated session metadata or validity check failed.');
}
$db->update('users', ['activation_required' => 1], ['id' => 1]);
if ($service->isValid($session, 1)) {
    throw new RuntimeException('A normal session remained valid after the account entered recovery.');
}
try {
    $service->establish($session, 1, 'webauthn', 'uv');
    throw new RuntimeException('A normal session was created while recovery was required.');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'Account activation or recovery is required before sign-in.') {
        throw $error;
    }
}
if ((int) $db->fetchOne('SELECT COUNT(*) FROM user_sessions WHERE user_id = ?', [1]) !== 1) {
    throw new RuntimeException('A rejected recovery-gated session was persisted.');
}
$revokedAt = $db->fetchOne('SELECT revoked_at FROM user_sessions WHERE id = ?', [$sessionId]);
if ($revokedAt === null) {
    throw new RuntimeException('A session was not revoked after activation became required.');
}
$db->update('users', ['activation_required' => 0], ['id' => 1]);
$service->revokeUser(1);
if ($service->isValid($session, 1)) {
    throw new RuntimeException('A revoked session remained valid.');
}
session_destroy();

echo "Authentication session security test: OK\n";
