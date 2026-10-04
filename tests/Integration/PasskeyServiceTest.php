<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use LexNova\Clock\SystemClock;
use LexNova\Handler\Auth\PasskeyRegisterHandler;
use LexNova\Service\AuditService;
use LexNova\Service\AuthSessionService;
use LexNova\Service\PasskeyService;
use LexNova\Service\PasswordService;
use LexNova\Service\RateLimitService;
use LexNova\Service\StepUpService;
use LexNova\Service\TotpService;
use LexNova\Service\UserService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\Session;
use Mezzio\Session\SessionMiddleware;
use Symfony\Component\Uid\Uuid;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\TrustPath\EmptyTrustPath;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$db->executeStatement('CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username VARCHAR(255) NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    password_login_enabled INTEGER NOT NULL DEFAULT 1,
    mfa_required INTEGER NOT NULL DEFAULT 0,
    activation_required INTEGER NOT NULL DEFAULT 0,
    role VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL
)');
$db->executeStatement('CREATE TABLE user_authenticators (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    kind VARCHAR(20) NOT NULL,
    credential_id VARCHAR(1024) DEFAULT NULL UNIQUE,
    credential_data TEXT DEFAULT NULL,
    secret_enc TEXT DEFAULT NULL,
    label VARCHAR(100) NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    last_used_at DATETIME DEFAULT NULL
)');
$db->executeStatement('CREATE TABLE system_settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT NOT NULL, updated_at DATETIME NOT NULL)');
$db->executeStatement('CREATE TABLE rate_limit_buckets (
    id INTEGER PRIMARY KEY AUTOINCREMENT, ip VARCHAR(45) NOT NULL, endpoint VARCHAR(50) NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 1, blocked_until DATETIME NULL, last_at DATETIME NOT NULL,
    UNIQUE (ip, endpoint)
)');

$passwords = new PasswordService(['security' => [
    'password_policy' => ['min_length' => 8, 'max_length' => 256, 'min_score' => 0],
]]);
$users = new UserService($db, $passwords);
$userId = $users->create('fido-user', '', 'admin', false);
if ($users->verifyCredentials('fido-user', 'anything') !== null) {
    throw new RuntimeException('A Passkey-only account accepted a password.');
}

$passkeys = new PasskeyService($db, 'https://lexnova.example.test');
$db->insert('system_settings', [
    'setting_key' => 'auth.webauthn.rp_id',
    'setting_value' => 'old.example.test',
    'updated_at' => '2025-01-01 00:00:00',
]);
$db->insert('system_settings', [
    'setting_key' => 'auth.webauthn.origin',
    'setting_value' => 'https://old.example.test',
    'updated_at' => '2025-01-01 00:00:00',
]);
$registration = json_decode(
    $passkeys->createRegistrationOptions(['id' => $userId, 'username' => 'fido-user']),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (!is_array($registration) || !isset($registration['challenge'], $registration['user']['id']) || ($registration['timeout'] ?? null) !== 120000) {
    throw new RuntimeException('WebAuthn registration options could not be serialized.');
}
if ($db->fetchOne("SELECT setting_value FROM system_settings WHERE setting_key = 'auth.webauthn.rp_id'") !== 'lexnova.example.test'
    || $db->fetchOne("SELECT setting_value FROM system_settings WHERE setting_key = 'auth.webauthn.origin'") !== 'https://lexnova.example.test'
) {
    throw new RuntimeException('RP ID and Origin pins were not refreshed when no credentials existed.');
}
$selection = $registration['authenticatorSelection'] ?? [];
if (($selection['userVerification'] ?? null) !== 'required' || ($selection['residentKey'] ?? null) !== 'preferred') {
    throw new RuntimeException('Registration does not require user verification with a preferred resident key.');
}
$hardwareRegistration = json_decode($passkeys->createRegistrationOptions(['id' => $userId, 'username' => 'fido-user'], true), true, flags: JSON_THROW_ON_ERROR);
if (($hardwareRegistration['authenticatorSelection']['authenticatorAttachment'] ?? null) !== 'cross-platform') {
    throw new RuntimeException('Hardware-key enrollment did not request cross-platform attachment.');
}
$authentication = json_decode($passkeys->createAuthenticationOptions(['id' => $userId, 'username' => 'fido-user']), true, flags: JSON_THROW_ON_ERROR);
if (!is_array($authentication) || !isset($authentication['challenge'], $authentication['rpId']) || ($authentication['timeout'] ?? null) !== 120000) {
    throw new RuntimeException('WebAuthn authentication options could not be serialized.');
}

$credentialSource = new PublicKeyCredentialSource(
    'credential-id',
    'public-key',
    ['usb', 'nfc'],
    'none',
    EmptyTrustPath::create(),
    Uuid::fromString('fa2b99dc-9e39-4257-8f92-4a30d23c4118'),
    'public-key-data',
    'user-handle',
    0,
    otherUI: ['authenticator_attachment' => 'cross-platform'],
    backupEligible: false,
    backupStatus: false,
    uvInitialized: true,
);
$serializer = (new WebauthnSerializerFactory(new AttestationStatementSupportManager()))->create();
$credentialId = rtrim(strtr(base64_encode('credential-id'), '+/', '-_'), '=');
$db->insert('user_authenticators', [
    'user_id' => $userId,
    'kind' => 'webauthn',
    'credential_id' => $credentialId,
    'credential_data' => $serializer->serialize($credentialSource, 'json'),
    'label' => 'Test key',
    'created_at' => '2026-08-14 00:00:00',
]);
$userB = $users->create('fido-user-b', '', 'admin', false);
$credentialSourceB = new PublicKeyCredentialSource(
    'credential-id-b',
    'public-key',
    ['internal'],
    'none',
    EmptyTrustPath::create(),
    Uuid::fromString('fa2b99dc-9e39-4257-8f92-4a30d23c4118'),
    'public-key-data-b',
    'user-handle-b',
    0,
    otherUI: ['authenticator_attachment' => 'platform'],
    backupEligible: true,
    backupStatus: true,
    uvInitialized: true,
);
$credentialIdB = rtrim(strtr(base64_encode('credential-id-b'), '+/', '-_'), '=');
$db->insert('user_authenticators', [
    'user_id' => $userB,
    'kind' => 'webauthn',
    'credential_id' => $credentialIdB,
    'credential_data' => $serializer->serialize($credentialSourceB, 'json'),
    'label' => 'User B key',
    'created_at' => '2026-08-14 00:00:00',
]);

$audit = new AuditService($db);
$totp = new TotpService(bin2hex(random_bytes(32)));
$registerHandler = new PasskeyRegisterHandler(
    $passkeys,
    $users,
    new StepUpService($passkeys, $users, $totp, $audit),
    new AuthSessionService($db),
    new RateLimitService($db, new SystemClock()),
    $audit,
);
$actorSession = new Session(['user_id' => $userId, 'auth_session_id' => 1], 'admin-session');
$request = (new ServerRequest([], [], new Uri('https://lexnova.example.test/admin/passkeys/register/options'), 'POST'))
    ->withParsedBody(['__csrf' => 'valid-test-token', 'user_id' => $userB])
    ->withAttribute(SessionMiddleware::SESSION_ATTRIBUTE, $actorSession)
    ->withAttribute(CsrfMiddleware::GUARD_ATTRIBUTE, new class {
        public function validateToken(string $token): bool
        {
            return $token === 'valid-test-token';
        }
    });
$response = $registerHandler->handle($request);
if ($response->getStatusCode() !== 403) {
    throw new RuntimeException('An administrator could enroll their own passkey into another account.');
}

$authenticationA = json_decode($passkeys->createAuthenticationOptions(['id' => $userId, 'username' => 'fido-user']), true, flags: JSON_THROW_ON_ERROR);
$allowedIds = array_column($authenticationA['allowCredentials'] ?? [], 'id');
if (($authenticationA['userVerification'] ?? null) !== 'required' || $allowedIds !== [$credentialId]) {
    throw new RuntimeException('Username-first options did not require UV and scope credentials to the expected user.');
}
$assertionFromB = json_encode([
    'type' => 'public-key',
    'id' => $credentialIdB,
    'rawId' => $credentialIdB,
    'response' => [
        'clientDataJSON' => rtrim(strtr(base64_encode(json_encode([
            'type' => 'webauthn.get',
            'challenge' => $authenticationA['challenge'],
            'origin' => 'https://lexnova.example.test',
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '='),
        'authenticatorData' => rtrim(strtr(base64_encode(random_bytes(32) . "\x01" . pack('N', 0)), '+/', '-_'), '='),
        'signature' => rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '='),
        'userHandle' => null,
    ],
], JSON_THROW_ON_ERROR);
try {
    $passkeys->finishAuthentication(json_encode($authenticationA, JSON_THROW_ON_ERROR), $assertionFromB, $userId);
    throw new RuntimeException('User B credential was accepted for User A.');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'Unknown passkey.') {
        throw $error;
    }
}
$credentials = $passkeys->listForUser($userId);
if (count($credentials) !== 1
    || $credentials[0]['label'] !== 'Test key'
    || $credentials[0]['kind'] !== 'Cross-platform Authenticator (Geräteart nicht verifiziert)'
    || $credentials[0]['attachment'] !== 'cross-platform'
    || $credentials[0]['transports'] !== ['usb', 'nfc']
    || $credentials[0]['aaguid'] !== 'fa2b99dc-9e39-4257-8f92-4a30d23c4118'
    || !$users->hasPasskey($userId)
) {
    throw new RuntimeException('Passkey credential management is incomplete.');
}
if (!$passkeys->renameForUser((int) $credentials[0]['id'], $userId, 'Backup key')
    || $passkeys->listForUser($userId)[0]['label'] !== 'Backup key'
) {
    throw new RuntimeException('Passkey name could not be changed.');
}
if (!$passkeys->deleteForUser((int) $credentials[0]['id'], $userId) || $users->hasPasskey($userId)) {
    throw new RuntimeException('Passkey credential could not be deleted safely.');
}

$db->update('system_settings', [
    'setting_value' => 'lexnova.example.test',
    'updated_at' => '2026-10-04 00:00:00',
], ['setting_key' => 'auth.webauthn.rp_id']);
$db->update('system_settings', [
    'setting_value' => 'https://lexnova.example.test',
    'updated_at' => '2026-10-04 00:00:00',
], ['setting_key' => 'auth.webauthn.origin']);
$changedDomain = new PasskeyService($db, 'https://other.example.test');
try {
    $changedDomain->createAuthenticationOptions(['id' => $userB, 'username' => 'fido-user-b']);
    throw new RuntimeException('A WebAuthn RP ID change was not blocked while credentials exist.');
} catch (RuntimeException $error) {
    if (!str_contains($error->getMessage(), 'RP ID changed')) {
        throw $error;
    }
}

echo "Passkey service integration test: OK\n";
