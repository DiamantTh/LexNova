<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use LexNova\Service\AuthenticationPolicyService;
use LexNova\Service\PasswordService;
use LexNova\Service\UserService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$db->executeStatement('CREATE TABLE users (
    id INTEGER PRIMARY KEY, username TEXT NOT NULL, password_hash TEXT NOT NULL,
    password_login_enabled INTEGER NOT NULL, mfa_required INTEGER NOT NULL,
    activation_required INTEGER NOT NULL DEFAULT 0, role TEXT NOT NULL, created_at DATETIME NOT NULL
)');
$db->executeStatement('CREATE TABLE user_authenticators (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, kind TEXT NOT NULL,
    credential_id TEXT, credential_data TEXT, secret_enc TEXT, label TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, last_used_at DATETIME NULL
)');
$users = new UserService($db, new PasswordService(['security' => [
    'password_policy' => ['min_length' => 8, 'max_length' => 256, 'min_score' => 0],
]]));
$policy = new AuthenticationPolicyService($users);
$insertUser = static function (int $id, bool $password, bool $mfa) use ($db): void {
    $db->insert('users', [
        'id' => $id,
        'username' => 'user-' . $id,
        'password_hash' => 'unused-test-hash',
        'password_login_enabled' => $password ? 1 : 0,
        'mfa_required' => $mfa ? 1 : 0,
        'activation_required' => 0,
        'role' => 'admin',
        'created_at' => '2026-10-04 00:00:00',
    ]);
};
$addAuthenticator = static function (int $userId, string $kind, bool $active = true) use ($db): void {
    $db->insert('user_authenticators', [
        'user_id' => $userId,
        'kind' => $kind,
        'credential_id' => $kind === 'webauthn' ? 'credential-' . $userId : null,
        'credential_data' => $kind === 'webauthn' ? 'credential-source' : null,
        'secret_enc' => $kind === 'totp' ? 'ciphertext' : null,
        'label' => 'Test',
        'is_active' => $active ? 1 : 0,
        'created_at' => '2026-10-04 00:00:00',
    ]);
};

$insertUser(1, false, true);
$addAuthenticator(1, 'webauthn');
if ($policy->canRemovePasskey(1)) {
    throw new RuntimeException('Removing the final passkey-only login path was allowed.');
}

$insertUser(2, true, true);
$addAuthenticator(2, 'webauthn');
$addAuthenticator(2, 'totp');
if (!$policy->canRemovePasskey(2)) {
    throw new RuntimeException('Password plus a remaining TOTP factor should remain a valid path.');
}

$insertUser(3, true, true);
$addAuthenticator(3, 'totp');
if ($policy->canRemoveTotpKey(3) || $policy->canResetTotp(3)) {
    throw new RuntimeException('Removing the only MFA factor from a password account was allowed.');
}

$insertUser(4, true, false);
$addAuthenticator(4, 'totp');
if ($policy->canDisablePasswordLogin(4)) {
    throw new RuntimeException('TOTP alone was incorrectly accepted as a primary login path.');
}

echo "Authentication policy security test: OK\n";
