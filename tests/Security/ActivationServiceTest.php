<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use LexNova\Service\ActivationService;
use LexNova\Service\AuditService;
use LexNova\Service\AuthSessionService;
use LexNova\Service\PasswordService;
use LexNova\Service\UserService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$db->executeStatement('CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL, password_hash TEXT NOT NULL,
    password_login_enabled INTEGER NOT NULL DEFAULT 1, activation_required INTEGER NOT NULL DEFAULT 0,
    mfa_required INTEGER NOT NULL DEFAULT 0, role TEXT NOT NULL DEFAULT \'admin\', created_at DATETIME NOT NULL
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
$db->executeStatement('CREATE TABLE user_authenticators (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, kind TEXT NOT NULL,
    credential_id TEXT NULL, credential_data TEXT NULL, secret_enc TEXT NULL, label TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, last_used_at DATETIME NULL
)');

$now = gmdate('Y-m-d H:i:s');
$testPassword = 'recovery-test-password';
$db->insert('users', [
    'username' => 'recovery-target',
    'password_hash' => password_hash($testPassword, PASSWORD_BCRYPT, ['cost' => 4]),
    'password_login_enabled' => 0,
    'activation_required' => 0,
    'mfa_required' => 0,
    'role' => 'admin',
    'created_at' => $now,
]);
$userId = (int) $db->lastInsertId();
$db->insert('users', [
    'username' => 'recovery-operator',
    'password_hash' => password_hash('operator-password', PASSWORD_BCRYPT, ['cost' => 4]),
    'password_login_enabled' => 1,
    'activation_required' => 0,
    'mfa_required' => 0,
    'role' => 'admin',
    'created_at' => $now,
]);
$actorId = (int) $db->lastInsertId();
$db->insert('users', [
    'username' => 'different-account',
    'password_hash' => password_hash('different-password', PASSWORD_BCRYPT, ['cost' => 4]),
    'password_login_enabled' => 1,
    'activation_required' => 1,
    'mfa_required' => 0,
    'role' => 'admin',
    'created_at' => $now,
]);
$otherUserId = (int) $db->lastInsertId();
$oldAuthenticatorIds = [];
foreach ([
    ['kind' => 'webauthn', 'credential_id' => 'pre-recovery-key-a-raw-marker', 'credential_data' => 'old-key-a-data'],
    ['kind' => 'webauthn', 'credential_id' => 'pre-recovery-key-b-raw-marker', 'credential_data' => 'old-key-b-data'],
    ['kind' => 'totp', 'secret_enc' => 'pre-recovery-totp-secret-t1-marker'],
    ['kind' => 'totp', 'secret_enc' => 'pre-recovery-totp-secret-t2-marker'],
] as $index => $authenticator) {
    $db->insert('user_authenticators', [
        'user_id' => $userId,
        'kind' => $authenticator['kind'],
        'credential_id' => $authenticator['credential_id'] ?? null,
        'credential_data' => $authenticator['credential_data'] ?? null,
        'secret_enc' => $authenticator['secret_enc'] ?? null,
        'label' => 'Existing credential ' . $index,
        'created_at' => $now,
    ]);
    $oldAuthenticatorIds[] = (int) $db->lastInsertId();
}
$db->insert('user_authenticators', [
    'user_id' => $otherUserId,
    'kind' => 'webauthn',
    'credential_id' => 'foreign-user-credential',
    'credential_data' => 'foreign-user-credential-data',
    'label' => 'Other account key',
    'created_at' => $now,
]);
$foreignCredentialId = (int) $db->lastInsertId();
$db->insert('user_sessions', [
    'user_id' => $userId,
    'session_hash' => hash('sha256', 'existing-session'),
    'auth_method' => 'webauthn',
    'auth_strength' => 'uv',
    'created_at' => $now,
    'last_activity_at' => $now,
    'absolute_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
]);

$audit = new AuditService($db);
$sessions = new AuthSessionService($db);
$service = new ActivationService($db, $audit, $sessions);
$userService = new UserService($db, new PasswordService([
    'security' => ['password' => ['algo' => PASSWORD_BCRYPT, 'options' => ['cost' => 4]]],
]));

// Recovery issuance hashes the one-time ticket, preserves existing credentials,
// puts the account into the recovery gate, and revokes prior sessions atomically.
$ticket = $service->issue($userId, $actorId, true);
$ticketHash = hash('sha256', $ticket);
$issued = $service->userForTicketHash($ticketHash);
if (($issued['purpose'] ?? null) !== 'recovery' || $service->latestTicketPurpose($userId) !== 'recovery') {
    throw new RuntimeException('Recovery ticket metadata or purpose was not retained.');
}
$storedHash = (string) $db->fetchOne('SELECT token_hash FROM user_activation_tickets WHERE user_id = ?', [$userId]);
$expiry = (string) $db->fetchOne('SELECT expires_at FROM user_activation_tickets WHERE user_id = ?', [$userId]);
if (hash_equals($storedHash, $ticket) || strtotime($expiry) - strtotime($now) < 86390 || strtotime($expiry) - strtotime($now) > 86410) {
    throw new RuntimeException('Ticket was not stored as a SHA-256 hash with a 24-hour lifetime.');
}
if ((int) $db->fetchOne('SELECT revoked_at IS NOT NULL FROM user_sessions WHERE user_id = ?', [$userId]) !== 1
    || (int) $db->fetchOne('SELECT activation_required FROM users WHERE id = ?', [$userId]) !== 1
    || (int) $db->fetchOne('SELECT COUNT(*) FROM user_authenticators WHERE user_id = ?', [$userId]) !== 4
) {
    throw new RuntimeException('Recovery did not gate the account, revoke sessions, and retain credentials.');
}
if ($userService->verifyCredentials('recovery-target', $testPassword) !== null) {
    throw new RuntimeException('Password login remained available while recovery was required.');
}
// Reissuing a ticket invalidates its predecessor but retains the recovery purpose.
$replacement = $service->issue($userId, $actorId, true);
$replacementHash = hash('sha256', $replacement);
if ($service->userForTicketHash($ticketHash) !== null
    || ($service->userForTicketHash($replacementHash)['purpose'] ?? null) !== 'recovery'
    || $service->latestTicketPurpose($userId) !== 'recovery'
) {
    throw new RuntimeException('A replacement recovery ticket did not supersede and retain the purpose of its predecessor.');
}

// Expiry and account binding are checked server-side before a ticket is consumed.
$db->executeStatement('UPDATE user_activation_tickets SET expires_at = ? WHERE token_hash = ?', [gmdate('Y-m-d H:i:s', time() - 1), $replacementHash]);
if ($service->userForTicketHash($replacementHash) !== null) {
    throw new RuntimeException('An expired ticket remained usable.');
}
try {
    $service->complete($otherUserId, $replacementHash, static fn (): int => 55);
    throw new RuntimeException('A ticket was usable for a different account.');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'Activation ticket expired or already used.') {
        throw $error;
    }
}

$completionTicket = $service->issue($userId, $actorId, true);
$completionHash = hash('sha256', $completionTicket);
try {
    $service->complete($otherUserId, $completionHash, static fn (): int => 55);
    throw new RuntimeException('A ticket was usable for a different account.');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'Activation ticket expired or already used.') {
        throw $error;
    }
}
try {
    $service->complete($userId, $completionHash, static function (): int {
        throw new RuntimeException('simulated registration verification failure');
    });
    throw new RuntimeException('An unsuccessful enrollment completed recovery.');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'simulated registration verification failure') {
        throw $error;
    }
}
if ($userService->findById($userId)['activation_required'] !== true
    || $db->fetchOne('SELECT consumed_at FROM user_activation_tickets WHERE token_hash = ?', [$completionHash]) !== null
    || (int) $db->fetchOne('SELECT COUNT(*) FROM user_authenticators WHERE user_id = ?', [$userId]) !== 4
) {
    throw new RuntimeException('Failed enrollment was not rolled back atomically.');
}
try {
    $service->complete($userId, $completionHash, static fn (): int => $foreignCredentialId);
    throw new RuntimeException('A credential owned by another user completed recovery.');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'A newly verified FIDO2 credential was not persisted for this account.') {
        throw $error;
    }
}
try {
    $service->complete($userId, $completionHash, static fn (): int => $oldAuthenticatorIds[0]);
    throw new RuntimeException('A pre-recovery FIDO2 credential completed recovery without a new enrollment.');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'A newly verified FIDO2 credential was not persisted for this account.') {
        throw $error;
    }
}
if ($db->fetchOne('SELECT consumed_at FROM user_activation_tickets WHERE token_hash = ?', [$completionHash]) !== null
    || (int) $db->fetchOne('SELECT COUNT(*) FROM user_authenticators WHERE user_id = ?', [$userId]) !== 4
) {
    throw new RuntimeException('Cross-account credential verification did not roll back.');
}
try {
    $service->complete($userId, $completionHash, static fn (): int => 999);
    throw new RuntimeException('Recovery completed without a persisted FIDO2 authenticator.');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'A newly verified FIDO2 credential was not persisted for this account.') {
        throw $error;
    }
}
if ($userService->findById($userId)['activation_required'] !== true
    || $db->fetchOne('SELECT consumed_at FROM user_activation_tickets WHERE token_hash = ?', [$completionHash]) !== null
) {
    throw new RuntimeException('A missing FIDO2 credential consumed the recovery ticket.');
}

$db->executeStatement("CREATE TRIGGER fail_recovery_cleanup BEFORE DELETE ON user_authenticators
    WHEN OLD.user_id = {$userId} BEGIN SELECT RAISE(ABORT, 'simulated credential cleanup failure'); END");
try {
    $service->complete($userId, $completionHash, static function () use ($db, $userId): int {
        $db->insert('user_authenticators', [
            'user_id' => $userId,
            'kind' => 'webauthn',
            'credential_id' => 'new-key-before-cleanup-failure',
            'credential_data' => 'new-key-data-before-cleanup-failure',
            'label' => 'New recovery key',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return (int) $db->lastInsertId();
    });
    throw new RuntimeException('Recovery completed despite credential cleanup failure.');
} catch (Throwable $error) {
    if ($error->getMessage() === 'Recovery completed despite credential cleanup failure.') {
        throw $error;
    }
}
$db->executeStatement('DROP TRIGGER fail_recovery_cleanup');
if ($userService->findById($userId)['activation_required'] !== true
    || $db->fetchOne('SELECT consumed_at FROM user_activation_tickets WHERE token_hash = ?', [$completionHash]) !== null
    || (int) $db->fetchOne('SELECT COUNT(*) FROM user_authenticators WHERE user_id = ?', [$userId]) !== 4
) {
    throw new RuntimeException('Credential cleanup failure partially committed recovery changes.');
}

// Completion requires a persisted WebAuthn credential and audits the ticket's
// recovery purpose without recording credential bytes or the ticket itself.
$credentialId = $service->complete($userId, $completionHash, static function () use ($db, $userId): int {
    $db->insert('user_authenticators', [
        'user_id' => $userId,
        'kind' => 'webauthn',
        'credential_id' => 'credential-raw-marker',
        'credential_data' => 'serialized-credential-raw-marker',
        'label' => 'New key',
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]);

    return (int) $db->lastInsertId();
}, '192.0.2.20');
if ($credentialId <= 0 || $userService->findById($userId)['activation_required'] !== false
    || $userService->findById($userId)['mfa_required'] !== true
    || (int) $db->fetchOne('SELECT COUNT(*) FROM user_authenticators WHERE user_id = ?', [$userId]) !== 1
) {
    throw new RuntimeException('Recovery did not complete after verified FIDO2 persistence.');
}
$remaining = $db->fetchAssociative('SELECT id, kind, credential_id FROM user_authenticators WHERE user_id = ?', [$userId]);
$remainingAuthenticatorIds = array_map('intval', array_column($db->fetchAllAssociative('SELECT id FROM user_authenticators WHERE user_id = ?', [$userId]), 'id'));
if ((int) $remaining['id'] !== $credentialId
    || $remaining['kind'] !== 'webauthn'
    || $remaining['credential_id'] !== 'credential-raw-marker'
    || array_intersect($oldAuthenticatorIds, $remainingAuthenticatorIds) !== []
) {
    throw new RuntimeException('Full recovery did not retain only the newly registered FIDO2 credential.');
}
$actions = array_column($audit->recent(20), 'action');
if (!in_array('auth.recovery_activation_completed', $actions, true)
    || !in_array('auth.recovery_webauthn_registered', $actions, true)
    || !in_array('auth.recovery_webauthn_credentials_revoked', $actions, true)
    || !in_array('auth.recovery_totp_credentials_revoked', $actions, true)
    || in_array('auth.activation_completed', $actions, true)
) {
    throw new RuntimeException('Recovery purpose was not preserved in the completion audit.');
}
if ($db->fetchOne('SELECT consumed_at FROM user_activation_tickets WHERE token_hash = ?', [$completionHash]) === null
    || $service->userForTicketHash($completionHash) !== null
) {
    throw new RuntimeException('A successful recovery ticket was not consumed exactly once.');
}
try {
    $service->complete($userId, $completionHash, static fn (): int => 99);
    throw new RuntimeException('A consumed recovery ticket was reusable.');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'Account is not awaiting activation or recovery.') {
        throw $error;
    }
}
$auditText = json_encode($audit->recent(20), JSON_THROW_ON_ERROR);
if (str_contains($auditText, $ticket)
    || str_contains($auditText, $replacement)
    || str_contains($auditText, $completionTicket)
    || str_contains($auditText, 'credential-raw-marker')
    || str_contains($auditText, 'serialized-credential-raw-marker')
    || str_contains($auditText, 'pre-recovery-key-a-raw-marker')
    || str_contains($auditText, 'pre-recovery-key-b-raw-marker')
    || str_contains($auditText, 'pre-recovery-totp-secret-t1-marker')
    || str_contains($auditText, 'pre-recovery-totp-secret-t2-marker')
    || str_contains($auditText, 'new-key-before-cleanup-failure')
    || str_contains($auditText, 'auth.recovery_activation_ticket_issued') === false
) {
    throw new RuntimeException('Recovery audit event is missing or contains ticket/credential material.');
}

// First enrollment remains a distinct purpose and audit event.
$db->insert('users', [
    'username' => 'first-activation',
    'password_hash' => password_hash('unused', PASSWORD_BCRYPT, ['cost' => 4]),
    'password_login_enabled' => 0,
    'activation_required' => 1,
    'mfa_required' => 0,
    'role' => 'admin',
    'created_at' => gmdate('Y-m-d H:i:s'),
]);
$activationUserId = (int) $db->lastInsertId();
$db->insert('user_authenticators', [
    'user_id' => $activationUserId,
    'kind' => 'webauthn',
    'credential_id' => 'pre-existing-first-activation-passkey',
    'credential_data' => 'pre-existing-first-activation-passkey-data',
    'label' => 'Pre-existing key',
    'created_at' => gmdate('Y-m-d H:i:s'),
]);
$preExistingActivationPasskeyId = (int) $db->lastInsertId();
$db->insert('user_authenticators', [
    'user_id' => $activationUserId,
    'kind' => 'totp',
    'secret_enc' => 'pre-existing-first-activation-totp-secret-marker',
    'label' => 'Pre-existing authenticator',
    'created_at' => gmdate('Y-m-d H:i:s'),
]);
$preExistingActivationTotpId = (int) $db->lastInsertId();
$activationTicket = $service->issue($activationUserId, null, false);
$activationHash = hash('sha256', $activationTicket);
$service->complete($activationUserId, $activationHash, static function () use ($db, $activationUserId): int {
    $db->insert('user_authenticators', [
        'user_id' => $activationUserId,
        'kind' => 'webauthn',
        'credential_id' => 'first-activation-credential',
        'credential_data' => 'verified-credential-persisted-by-registration-callback',
        'label' => 'First key',
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]);

    return (int) $db->lastInsertId();
});
$actions = array_column($audit->recent(20), 'action');
if (!in_array('auth.activation_completed', $actions, true)
    || (int) $db->fetchOne('SELECT COUNT(*) FROM user_authenticators WHERE user_id = ?', [$activationUserId]) !== 3
    || (int) $db->fetchOne('SELECT COUNT(*) FROM user_authenticators WHERE id IN (?, ?)', [$preExistingActivationPasskeyId, $preExistingActivationTotpId]) !== 2
) {
    throw new RuntimeException('Ordinary first enrollment lost its activation audit purpose.');
}

echo "Activation and recovery ticket security test: OK\n";
