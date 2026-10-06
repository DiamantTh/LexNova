<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use LexNova\Handler\Admin\TotpKeyDeleteHandler;
use LexNova\Handler\Admin\TotpResetHandler;
use LexNova\Handler\Auth\PasskeyDeleteHandler;
use LexNova\Service\AuditService;
use LexNova\Service\AuthenticationPolicyService;
use LexNova\Service\AuthSessionService;
use LexNova\Service\PasskeyService;
use LexNova\Service\PasswordService;
use LexNova\Service\StepUpService;
use LexNova\Service\TotpService;
use LexNova\Service\UserService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\Session;
use Mezzio\Session\SessionMiddleware;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$db->executeStatement('CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL, password_hash TEXT NOT NULL,
    password_login_enabled INTEGER NOT NULL DEFAULT 0, mfa_required INTEGER NOT NULL DEFAULT 1,
    activation_required INTEGER NOT NULL DEFAULT 0, role TEXT NOT NULL DEFAULT \'admin\', created_at DATETIME NOT NULL
)');
$db->executeStatement('CREATE TABLE user_authenticators (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, kind TEXT NOT NULL,
    credential_id TEXT, credential_data TEXT, secret_enc TEXT, label TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, last_used_at DATETIME NULL
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

$now = gmdate('Y-m-d H:i:s');
foreach (['last-passkey-target', 'last-totp-target', 'admin-operator'] as $username) {
    $db->insert('users', [
        'username' => $username,
        'password_hash' => password_hash('unused', PASSWORD_BCRYPT, ['cost' => 4]),
        'password_login_enabled' => 0,
        'mfa_required' => 1,
        'activation_required' => 0,
        'role' => 'admin',
        'created_at' => $now,
    ]);
}
$passkeyUserId = 1;
$totpUserId = 2;
$actorId = 3;
$db->insert('user_authenticators', [
    'user_id' => $passkeyUserId,
    'kind' => 'webauthn',
    'credential_id' => 'only-passkey-id',
    'credential_data' => 'serialized-passkey-data',
    'label' => 'Only passkey',
    'created_at' => $now,
]);
$passkeyAuthenticatorId = (int) $db->lastInsertId();
$totp = new TotpService(bin2hex(random_bytes(32)));
$totpCredential = $totp->generate('LexNova', 'last-totp-target');
$db->insert('user_authenticators', [
    'user_id' => $totpUserId,
    'kind' => 'totp',
    'secret_enc' => $totpCredential['encrypted'],
    'label' => 'Only TOTP',
    'created_at' => $now,
]);
$totpAuthenticatorId = (int) $db->lastInsertId();

$audit = new AuditService($db);
$users = new UserService($db, new PasswordService([]));
$policy = new AuthenticationPolicyService($users, $totp);
$sessions = new AuthSessionService($db);
$passkeys = new PasskeyService($db, 'https://lexnova.example.test', audit: $audit);
$stepUp = new StepUpService($passkeys, $users, $totp, $audit, $policy);
$csrf = new class {
    public function validateToken(string $token): bool
    {
        return hash_equals('recovery-guard-csrf', $token);
    }
};
$session = new Session([
    'user_id' => $actorId,
    'username' => 'admin-operator',
    'auth_session_id' => 123,
], 'recovery-guard-admin-session');
$request = static function (string $path, array $attributes, Session $session, object $csrf): ServerRequest {
    $request = (new ServerRequest(['REMOTE_ADDR' => '192.0.2.91'], [], new Uri('https://lexnova.example.test' . $path), 'POST'))
        ->withAttribute(SessionMiddleware::SESSION_ATTRIBUTE, $session)
        ->withAttribute(CsrfMiddleware::GUARD_ATTRIBUTE, $csrf)
        ->withParsedBody(['__csrf' => 'recovery-guard-csrf']);
    foreach ($attributes as $name => $value) {
        $request = $request->withAttribute((string) $name, $value);
    }

    return $request;
};

$passkeyHandler = new PasskeyDeleteHandler($passkeys, $users, $audit, $policy, $stepUp, $sessions);
$passkeyResponse = $passkeyHandler->handle($request(
    '/user/1/passkeys/' . $passkeyAuthenticatorId . '/delete',
    ['userId' => $passkeyUserId, 'credentialId' => $passkeyAuthenticatorId],
    $session,
    $csrf,
));
if ($passkeyResponse->getStatusCode() !== 302
    || (int) $db->fetchOne('SELECT COUNT(*) FROM user_authenticators WHERE id = ?', [$passkeyAuthenticatorId]) !== 1
    || !str_contains(implode(' ', (array) $session->get('flash_errors', [])), 'recovery ticket')
) {
    throw new RuntimeException('An administrator removed the target account\'s last FIDO2 sign-in path.');
}

$totpDeleteHandler = new TotpKeyDeleteHandler($users, $audit, $policy, $stepUp, $sessions);
$totpResponse = $totpDeleteHandler->handle($request(
    '/admin/users/' . $totpUserId . '/totp-keys/' . $totpAuthenticatorId . '/delete',
    ['userId' => $totpUserId, 'keyId' => $totpAuthenticatorId],
    $session,
    $csrf,
));
if ($totpResponse->getStatusCode() !== 302
    || (int) $db->fetchOne('SELECT COUNT(*) FROM user_authenticators WHERE id = ?', [$totpAuthenticatorId]) !== 1
    || !str_contains(implode(' ', (array) $session->get('flash_errors', [])), 'recovery ticket')
) {
    throw new RuntimeException('An administrator removed the target account\'s last TOTP sign-in path.');
}

$resetHandler = new TotpResetHandler($users, $audit, $policy, $stepUp, $sessions);
$resetResponse = $resetHandler->handle($request(
    '/admin/totp/reset/' . $totpUserId,
    ['id' => $totpUserId],
    $session,
    $csrf,
));
if ($resetResponse->getStatusCode() !== 302
    || (int) $db->fetchOne('SELECT COUNT(*) FROM user_authenticators WHERE id = ?', [$totpAuthenticatorId]) !== 1
    || !str_contains(implode(' ', (array) $session->get('flash_errors', [])), 'recovery-ticket path')
) {
    throw new RuntimeException('An administrator reset the target account\'s last TOTP sign-in path.');
}

echo "Administrative recovery removal guard test: OK\n";
