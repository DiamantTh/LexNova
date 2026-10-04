<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use LexNova\Service\ActivationService;
use LexNova\Service\AuditService;
use LexNova\Service\AuthSessionService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$db->executeStatement('CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL, activation_required INTEGER NOT NULL,
    mfa_required INTEGER NOT NULL DEFAULT 0
)');
$db->executeStatement('CREATE TABLE user_activation_tickets (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE,
    purpose VARCHAR(20) NOT NULL DEFAULT \'activation\', created_by_user_id INTEGER NULL,
    created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, consumed_at DATETIME NULL, revoked_at DATETIME NULL
)');
$db->executeStatement('CREATE TABLE user_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, session_hash CHAR(64) NOT NULL UNIQUE,
    auth_method TEXT NOT NULL, auth_strength TEXT NOT NULL, created_at DATETIME NOT NULL,
    last_activity_at DATETIME NOT NULL, absolute_expires_at DATETIME NOT NULL, revoked_at DATETIME NULL
)');
$db->executeStatement('CREATE TABLE audit_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER NULL, effective_user_id INTEGER NULL,
    actor_name TEXT NULL, action TEXT NOT NULL, target TEXT NULL, detail TEXT NULL, ip TEXT NULL, created_at DATETIME NOT NULL
)');
$db->executeStatement('CREATE TABLE user_authenticators (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, kind TEXT NOT NULL, credential_id TEXT, credential_data TEXT, secret_enc TEXT, label TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, last_used_at DATETIME NULL)');
$db->insert('users', ['username' => 'pending', 'activation_required' => 1]);
$userId = (int) $db->lastInsertId();
$audit = new AuditService($db);
$service = new ActivationService($db, $audit, new AuthSessionService($db));
$ticket = $service->issue($userId, 77, false);
$ticketHash = hash('sha256', $ticket);
$issued = $service->userForTicketHash($ticketHash);
if (($issued['purpose'] ?? null) !== 'activation') {
    throw new RuntimeException('Activation ticket metadata or hash was not persisted.');
}
$storedHash = (string) $db->fetchOne('SELECT token_hash FROM user_activation_tickets WHERE user_id = ?', [$userId]);
if (hash_equals($storedHash, $ticket)) {
    throw new RuntimeException('Activation ticket was stored without hashing.');
}
$registerCalls = 0;
$credentialId = $service->complete($userId, $ticketHash, static function () use (&$registerCalls, $db, $userId): int {
    ++$registerCalls;
    $db->insert('user_authenticators', [
        'user_id' => $userId,
        'kind' => 'webauthn',
        'credential_id' => 'credential-one',
        'credential_data' => 'serialized-source',
        'label' => 'First key',
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]);

    return 23;
});
if ($credentialId !== 23 || $registerCalls !== 1 || (int) $db->fetchOne('SELECT activation_required FROM users WHERE id = ?', [$userId]) !== 0) {
    throw new RuntimeException('Ticket completion did not activate the account and credential atomically.');
}
try {
    $service->complete($userId, $ticketHash, static fn (): int => 24);
    throw new RuntimeException('A consumed activation ticket was reusable.');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'Activation ticket expired or already used.') {
        throw $error;
    }
}
$auditText = json_encode($audit->recent(10), JSON_THROW_ON_ERROR);
if (str_contains($auditText, $ticket) || !str_contains($auditText, 'auth.activation_ticket_issued')) {
    throw new RuntimeException('Activation audit event is missing or contains the ticket.');
}

echo "Activation ticket security test: OK\n";
