<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use LexNova\Service\AuditService;
use LexNova\Service\PasskeyService;
use LexNova\Service\PasswordService;
use LexNova\Service\StepUpService;
use LexNova\Service\TotpService;
use LexNova\Service\UserService;
use Mezzio\Session\Session;
use OTPHP\TOTP;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$db->executeStatement('CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT, username VARCHAR(255) NOT NULL UNIQUE,
    password_hash TEXT NOT NULL, password_login_enabled INTEGER NOT NULL DEFAULT 1,
    mfa_required INTEGER NOT NULL DEFAULT 0, activation_required INTEGER NOT NULL DEFAULT 0,
    role VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL
)');
$db->executeStatement('CREATE TABLE user_authenticators (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, kind VARCHAR(20) NOT NULL,
    credential_id VARCHAR(1024) UNIQUE, credential_data TEXT, secret_enc TEXT,
    label VARCHAR(100) NOT NULL, is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL, last_used_at DATETIME DEFAULT NULL
)');
$db->executeStatement('CREATE TABLE audit_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER, effective_user_id INTEGER,
    actor_name VARCHAR(255), action VARCHAR(100) NOT NULL, target VARCHAR(255), detail TEXT, ip VARCHAR(45), created_at DATETIME NOT NULL
)');

$passwords = new PasswordService(['security' => [
    'password_policy' => ['min_length' => 8, 'max_length' => 256, 'min_score' => 0],
]]);
$users = new UserService($db, $passwords);
$userId = $users->create('step-up-user', '', 'admin', false);
$totp = new TotpService(bin2hex(random_bytes(32)), digits: 8, algorithm: 'sha256');
$totpData = $totp->generate('LexNova', 'step-up-user');
$ciphertext = $totp->encrypt($totpData['secret']);
$users->addTotpKey($userId, $ciphertext, 'Audit-safe key');
$audit = new AuditService($db);
$service = new StepUpService(
    new PasskeyService($db, 'https://lexnova.example.test'),
    $users,
    $totp,
    $audit,
);
$session = new Session([
    'user_id' => $userId,
    'username' => 'step-up-user',
    'auth_session_id' => 91,
], 'test-session');

$otp = TOTP::createFromSecret($totpData['secret']);
$otp->setDigits(8);
$otp->setDigest('sha256');
$code = $otp->now();
$action = 'auth.totp.delete';
$target = 'user:' . $userId . '/totp:12';

$service->verifyTotp($session, $code, $action, $target, '192.0.2.1');
if ($service->consume($session, 'auth.totp.reset', $target)) {
    throw new RuntimeException('Step-up grant was accepted for a different action.');
}
if ($service->consume($session, $action, $target)) {
    throw new RuntimeException('A grant consumed by a mismatched action was reusable.');
}

$service->verifyTotp($session, $code, $action, $target, '192.0.2.1');
if ($service->consume($session, $action, 'user:' . $userId . '/totp:13')) {
    throw new RuntimeException('Step-up grant was accepted for a different target.');
}

$service->verifyTotp($session, $code, $action, $target, '192.0.2.1');
if (!$service->consume($session, $action, $target) || $service->consume($session, $action, $target)) {
    throw new RuntimeException('A matching step-up grant was not one-shot.');
}

$session->set('step_up_pending', [
    'options' => '{}',
    'user_id' => $userId,
    'action' => $action,
    'target' => $target,
    'created_at' => time() - 121,
]);
try {
    $service->finishPasskey($session, '{}');
    throw new RuntimeException('An expired step-up challenge was accepted.');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'The step-up challenge expired or is no longer valid.') {
        throw $error;
    }
}
try {
    $service->finishPasskey($session, '{}');
    throw new RuntimeException('An expired step-up challenge could be replayed.');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'The step-up challenge expired or is no longer valid.') {
        throw $error;
    }
}

try {
    $service->verifyTotp($session, $code, 'auth.recovery', 'user:' . $userId . '/recovery', '192.0.2.1');
    throw new RuntimeException('TOTP was accepted for a FIDO2-only recovery action.');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'This action requires FIDO2 step-up.') {
        throw $error;
    }
}

$auditRows = $audit->recent(20);
$auditText = json_encode($auditRows, JSON_THROW_ON_ERROR);
if (str_contains($auditText, $totpData['secret']) || str_contains($auditText, $ciphertext) || str_contains($auditText, $code)) {
    throw new RuntimeException('Audit data contains TOTP secrets, ciphertext, or verification codes.');
}
if (count(array_filter($auditRows, static fn (array $row): bool => $row['action'] === 'auth.stepup_verified')) !== 3
    || count(array_filter($auditRows, static fn (array $row): bool => $row['action'] === 'auth.stepup_consumed')) !== 1
) {
    throw new RuntimeException('Successful step-up verification and consumption were not audited.');
}

echo "Step-up security test: OK\n";
