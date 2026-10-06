<?php

declare(strict_types=1);

use CBOR\ByteStringObject;
use CBOR\Encoder;
use CBOR\MapObject;
use Doctrine\DBAL\DriverManager;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use LexNova\Clock\SystemClock;
use LexNova\Frontend\SveltePageRenderer;
use LexNova\Handler\Auth\ActivationHandler;
use LexNova\Handler\Auth\PasskeyLoginHandler;
use LexNova\Service\ActivationService;
use LexNova\Service\AuditService;
use LexNova\Service\AuthSessionService;
use LexNova\Service\Fail2BanLogService;
use LexNova\Service\PasskeyService;
use LexNova\Service\PasswordService;
use LexNova\Service\RateLimitService;
use LexNova\Service\SystemSettingService;
use LexNova\Service\TotpService;
use LexNova\Service\UserService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\Session;
use Mezzio\Session\SessionMiddleware;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Uid\Uuid;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\TrustPath\EmptyTrustPath;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function recoveryBase64Url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

/** @return array{credential: string, raw_id: string, private_key: OpenSSLAsymmetricKey} */
function recoveryRegistrationResponse(string $optionsJson, string $rpId, string $origin): array
{
    $options = json_decode($optionsJson, true, flags: JSON_THROW_ON_ERROR);
    $privateKey = @openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ]);
    if (!$privateKey instanceof OpenSSLAsymmetricKey) {
        throw new RuntimeException('Could not create the test authenticator key.');
    }
    $details = openssl_pkey_get_details($privateKey);
    $ec = $details['ec'] ?? null;
    if (!is_array($ec) || !isset($ec['x'], $ec['y'])) {
        throw new RuntimeException('Could not read the test authenticator public key.');
    }
    $credentialId = random_bytes(32);
    $credentialIdEncoded = recoveryBase64Url($credentialId);
    $coseKey = (new Encoder())->encode([
        1 => 2,
        3 => -7,
        -1 => 1,
        -2 => ByteStringObject::create($ec['x']),
        -3 => ByteStringObject::create($ec['y']),
    ]);
    $authenticatorData = hash('sha256', $rpId, true)
        . "\x45"
        . pack('N', 0)
        . str_repeat("\x00", 16)
        . pack('n', strlen($credentialId))
        . $credentialId
        . $coseKey;
    $attestationObject = (new Encoder())->encode([
        'fmt' => 'none',
        'attStmt' => MapObject::create(),
        'authData' => ByteStringObject::create($authenticatorData),
    ]);
    $clientDataJson = json_encode([
        'type' => 'webauthn.create',
        'challenge' => $options['challenge'],
        'origin' => $origin,
        'crossOrigin' => false,
    ], JSON_THROW_ON_ERROR);
    $credential = json_encode([
        'id' => $credentialIdEncoded,
        'rawId' => $credentialIdEncoded,
        'type' => 'public-key',
        'response' => [
            'clientDataJSON' => recoveryBase64Url($clientDataJson),
            'attestationObject' => recoveryBase64Url($attestationObject),
            'transports' => ['internal'],
        ],
        'clientExtensionResults' => new stdClass(),
        'authenticatorAttachment' => 'platform',
    ], JSON_THROW_ON_ERROR);

    return ['credential' => $credential, 'raw_id' => $credentialId, 'private_key' => $privateKey];
}

/** @return array{credential: string, raw_id: string} */
function recoveryAssertionResponse(array $options, string $credentialId, OpenSSLAsymmetricKey $privateKey, string $rpId, string $origin): array
{
    $clientDataJson = json_encode([
        'type' => 'webauthn.get',
        'challenge' => $options['challenge'],
        'origin' => $origin,
        'crossOrigin' => false,
    ], JSON_THROW_ON_ERROR);
    $authenticatorData = hash('sha256', $rpId, true) . "\x05" . pack('N', 1);
    $signedData = $authenticatorData . hash('sha256', $clientDataJson, true);
    if (!openssl_sign($signedData, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Could not sign the test WebAuthn assertion.');
    }
    $credentialIdEncoded = recoveryBase64Url($credentialId);
    $credential = json_encode([
        'id' => $credentialIdEncoded,
        'rawId' => $credentialIdEncoded,
        'type' => 'public-key',
        'response' => [
            'clientDataJSON' => recoveryBase64Url($clientDataJson),
            'authenticatorData' => recoveryBase64Url($authenticatorData),
            'signature' => recoveryBase64Url($signature),
            'userHandle' => null,
        ],
        'clientExtensionResults' => new stdClass(),
    ], JSON_THROW_ON_ERROR);

    return ['credential' => $credential, 'raw_id' => $credentialId];
}

$db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$db->executeStatement('CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL, password_hash TEXT NOT NULL,
    password_login_enabled INTEGER NOT NULL DEFAULT 0, mfa_required INTEGER NOT NULL DEFAULT 1,
    activation_required INTEGER NOT NULL DEFAULT 0, role TEXT NOT NULL DEFAULT \'admin\', created_at DATETIME NOT NULL
)');
$db->executeStatement('CREATE TABLE user_activation_tickets (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE,
    purpose VARCHAR(20) NOT NULL DEFAULT \'activation\', created_by_user_id INTEGER NULL,
    created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, consumed_at DATETIME NULL, revoked_at DATETIME NULL
)');
$db->executeStatement('CREATE TABLE user_authenticators (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, kind TEXT NOT NULL,
    credential_id TEXT UNIQUE, credential_data TEXT, secret_enc TEXT, label TEXT NOT NULL,
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
$db->executeStatement('CREATE TABLE rate_limit_buckets (
    id INTEGER PRIMARY KEY AUTOINCREMENT, ip VARCHAR(45) NOT NULL, endpoint VARCHAR(50) NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 1, blocked_until DATETIME NULL, last_at DATETIME NOT NULL,
    UNIQUE (ip, endpoint)
)');
$db->executeStatement('CREATE TABLE system_settings (
    setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL, updated_at DATETIME NOT NULL
)');
$now = gmdate('Y-m-d H:i:s');
$db->insert('users', [
    'username' => 'passkey-only-recovery',
    'password_hash' => password_hash('unused-password', PASSWORD_BCRYPT, ['cost' => 4]),
    'password_login_enabled' => 0,
    'mfa_required' => 1,
    'activation_required' => 0,
    'role' => 'admin',
    'created_at' => $now,
]);
$userId = (int) $db->lastInsertId();
$db->insert('users', [
    'username' => 'recovery-browser-previous-admin',
    'password_hash' => password_hash('unused-password', PASSWORD_BCRYPT, ['cost' => 4]),
    'password_login_enabled' => 0,
    'mfa_required' => 1,
    'activation_required' => 0,
    'role' => 'admin',
    'created_at' => $now,
]);
$previousAdminId = (int) $db->lastInsertId();
$userHandle = hash('sha256', 'lexnova-webauthn-user:' . $userId, true);
$serializer = (new WebauthnSerializerFactory(new AttestationStatementSupportManager()))->create();
$oldCredentialIds = ['old-key-a-raw-marker', 'old-key-b-raw-marker'];
foreach ($oldCredentialIds as $index => $oldCredentialId) {
    $source = new PublicKeyCredentialSource(
        $oldCredentialId,
        'public-key',
        ['internal'],
        'none',
        EmptyTrustPath::create(),
        Uuid::fromString('fa2b99dc-9e39-4257-8f92-4a30d23c4118'),
        'unused-old-public-key-' . $index,
        $userHandle,
        0,
        uvInitialized: true,
    );
    $db->insert('user_authenticators', [
        'user_id' => $userId,
        'kind' => 'webauthn',
        'credential_id' => recoveryBase64Url($oldCredentialId),
        'credential_data' => $serializer->serialize($source, 'json'),
        'label' => 'Old key ' . ($index + 1),
        'created_at' => $now,
    ]);
}
$oldTotpSecrets = ['pre-recovery-totp-secret-one-marker', 'pre-recovery-totp-secret-two-marker'];
foreach ($oldTotpSecrets as $index => $oldTotpSecret) {
    $db->insert('user_authenticators', [
        'user_id' => $userId,
        'kind' => 'totp',
        'secret_enc' => $oldTotpSecret,
        'label' => 'Old authenticator ' . ($index + 1),
        'created_at' => $now,
    ]);
}
$db->insert('system_settings', [
    'setting_key' => 'auth.webauthn.rp_id',
    'setting_value' => 'lexnova.example.test',
    'updated_at' => $now,
]);
$db->insert('system_settings', [
    'setting_key' => 'auth.webauthn.origin',
    'setting_value' => 'https://lexnova.example.test',
    'updated_at' => $now,
]);
$db->insert('user_sessions', [
    'user_id' => $userId,
    'session_hash' => hash('sha256', 'session-revoked-at-recovery-start'),
    'auth_method' => 'webauthn',
    'auth_strength' => 'uv',
    'created_at' => $now,
    'last_activity_at' => $now,
    'absolute_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
]);
$db->insert('user_sessions', [
    'user_id' => $previousAdminId,
    'session_hash' => hash('sha256', 'previous-admin-session'),
    'auth_method' => 'webauthn',
    'auth_strength' => 'uv',
    'created_at' => $now,
    'last_activity_at' => $now,
    'absolute_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
]);
$previousAdminSessionId = (int) $db->lastInsertId();

$audit = new AuditService($db);
$sessions = new AuthSessionService($db);
$activation = new ActivationService($db, $audit, $sessions);
$userService = new UserService($db, new PasswordService([]));
$totp = new TotpService(bin2hex(random_bytes(32)));
$rateLimit = new RateLimitService($db, new SystemClock());
$passkeys = new PasskeyService($db, 'https://lexnova.example.test', audit: $audit);
$renderer = new SveltePageRenderer(dirname(__DIR__, 2) . '/httpdocs/assets/app/.vite/manifest.json');
$activationHandler = new ActivationHandler(
    $activation,
    $passkeys,
    $userService,
    $sessions,
    $rateLimit,
    $audit,
    $renderer,
);
$csrf = new class {
    public function validateToken(string $token): bool
    {
        return hash_equals('recovery-flow-csrf', $token);
    }

    public function generateToken(): string
    {
        return 'recovery-flow-csrf';
    }
};
$request = static function (string $path, Session $session, object $guard, array $body = [], array $attributes = []): ServerRequest {
    $request = (new ServerRequest(['REMOTE_ADDR' => '192.0.2.90'], [], new Uri('https://lexnova.example.test' . $path), 'POST'))
        ->withAttribute(SessionMiddleware::SESSION_ATTRIBUTE, $session)
        ->withAttribute(CsrfMiddleware::GUARD_ATTRIBUTE, $guard)
        ->withParsedBody(['__csrf' => 'recovery-flow-csrf', ...$body]);
    foreach ($attributes as $name => $value) {
        $request = $request->withAttribute($name, $value);
    }

    return $request;
};

$ticket = $activation->issue($userId, 900, true);
if ((int) $db->fetchOne('SELECT COUNT(*) FROM user_sessions WHERE user_id = ? AND revoked_at IS NULL', [$userId]) !== 0
    || (int) $db->fetchOne('SELECT activation_required FROM users WHERE id = ?', [$userId]) !== 1
) {
    throw new RuntimeException('Recovery start did not gate the passkey-only account and revoke its sessions.');
}
$recoverySession = new Session([
    'user_id' => $previousAdminId,
    'username' => 'stale-admin-identity',
    'role' => 'admin',
    'auth_session_id' => $previousAdminSessionId,
    'auth_method' => 'password',
    'auth_strength' => 'mfa',
], 'recovery-browser-session');
$verify = $activationHandler->handle($request('/activate/verify', $recoverySession, $csrf, ['ticket' => $ticket]));
if ($verify->getStatusCode() !== 200 || $recoverySession->has('user_id') || $recoverySession->has('auth_session_id')
    || $recoverySession->has('role')
    || $db->fetchOne('SELECT revoked_at FROM user_sessions WHERE id = ?', [$previousAdminSessionId]) === null
) {
    throw new RuntimeException('Passkey-only recovery ticket verification created an authenticated session.');
}
$optionsResponse = $activationHandler->handle($request('/activate/options', $recoverySession, $csrf));
$optionsJson = (string) $optionsResponse->getBody();
if ($optionsResponse->getStatusCode() !== 200) {
    throw new RuntimeException('Recovery context could not begin WebAuthn enrollment.');
}
$options = json_decode($optionsJson, true, flags: JSON_THROW_ON_ERROR);
if (($options['authenticatorSelection']['userVerification'] ?? null) !== 'required') {
    throw new RuntimeException('Recovery WebAuthn enrollment did not require user verification.');
}
$response = recoveryRegistrationResponse((string) $recoverySession->get('activation_registration')['options'], 'lexnova.example.test', 'https://lexnova.example.test');
$finish = $activationHandler->handle($request('/activate/finish', $recoverySession, $csrf, [
    'label' => 'New recovery credential C',
    'credential' => $response['credential'],
    'attachment' => 'platform',
]));
$finishBody = json_decode((string) $finish->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($finish->getStatusCode() !== 200 || ($finishBody['redirect'] ?? null) !== '/admin/login') {
    throw new RuntimeException('Successful recovery did not redirect to regular sign-in.');
}
if ($recoverySession->has('activation_ticket_hash')
    || $recoverySession->has('activation_user_id')
    || $recoverySession->has('activation_verified_at')
    || $recoverySession->has('activation_ticket_purpose')
    || $recoverySession->has('activation_registration')
    || $recoverySession->has('user_id')
    || $recoverySession->has('username')
    || $recoverySession->has('role')
    || $recoverySession->has('auth_session_id')
    || $recoverySession->has('auth_method')
    || $recoverySession->has('auth_strength')
) {
    throw new RuntimeException('Recovery completion left ticket state or authenticated identity in the PHP session.');
}
if ($recoverySession->get('flash_messages') !== ['Recovery abgeschlossen. Bitte melde dich mit dem neu registrierten FIDO2-Schlüssel an.']
    || (int) $db->fetchOne('SELECT COUNT(*) FROM user_sessions WHERE user_id = ? AND revoked_at IS NULL', [$userId]) !== 0
    || (int) $db->fetchOne('SELECT activation_required FROM users WHERE id = ?', [$userId]) !== 0
) {
    throw new RuntimeException('Recovery completion created a session or failed to leave the account ready for normal login.');
}
$remainingCredentials = $db->fetchAllAssociative('SELECT id, kind, credential_id, label FROM user_authenticators WHERE user_id = ?', [$userId]);
if (count($remainingCredentials) !== 1
    || $remainingCredentials[0]['kind'] !== 'webauthn'
    || $remainingCredentials[0]['label'] !== 'New recovery credential C'
    || (int) $remainingCredentials[0]['id'] <= 0
    || $userService->countPasskeys($userId) !== 1
    || $userService->countTotpKeys($userId) !== 0
) {
    throw new RuntimeException('Recovery did not replace A/B and both TOTP credentials with only C.');
}
$remainingRawId = (string) $response['raw_id'];
$remainingEncodedId = recoveryBase64Url($remainingRawId);
if ($remainingCredentials[0]['credential_id'] !== $remainingEncodedId) {
    throw new RuntimeException('Recovery removed or changed the newly enrolled credential C.');
}

// A previously enrolled credential no longer resolves server-side. The new
// credential is the only item in allowCredentials after recovery.
$loginOptions = json_decode($passkeys->createAuthenticationOptions(['id' => $userId, 'username' => 'passkey-only-recovery']), true, flags: JSON_THROW_ON_ERROR);
if (array_column($loginOptions['allowCredentials'] ?? [], 'id') !== [$remainingEncodedId]) {
    throw new RuntimeException('Regular username-first login did not offer only new credential C.');
}
$oldCredentialEncoded = recoveryBase64Url($oldCredentialIds[0]);
$oldAssertion = recoveryAssertionResponse(
    json_decode(json_encode($loginOptions, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR),
    $oldCredentialIds[0],
    $response['private_key'],
    'lexnova.example.test',
    'https://lexnova.example.test',
);
try {
    $passkeys->finishAuthentication(json_encode($loginOptions, JSON_THROW_ON_ERROR), $oldAssertion['credential'], $userId);
    throw new RuntimeException('A pre-recovery FIDO2 credential remained usable.');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'Unknown passkey.') {
        throw $error;
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$clockCache = new Psr16Cache(new ArrayAdapter());
$fail2ban = new Fail2BanLogService(
    new SystemSettingService($db, $clockCache),
    false,
    sys_get_temp_dir() . '/lexnova-recovery-flow-fail2ban.log',
);
$loginHandler = new PasskeyLoginHandler($passkeys, $userService, $sessions, $rateLimit, $audit, $fail2ban);
$loginSession = new Session([], 'normal-login-after-recovery');
$loginOptionsResponse = $loginHandler->handle($request('/admin/passkeys/login/options', $loginSession, $csrf, [
    'username' => 'passkey-only-recovery',
]));
if ($loginOptionsResponse->getStatusCode() !== 200) {
    throw new RuntimeException('Passkey-only user could not begin normal FIDO2 login after recovery.');
}
$newAssertion = recoveryAssertionResponse(json_decode((string) $loginSession->get('passkey_login')['options'], true, flags: JSON_THROW_ON_ERROR), $remainingRawId, $response['private_key'], 'lexnova.example.test', 'https://lexnova.example.test');
$loginFinish = $loginHandler->handle($request('/admin/passkeys/login/finish', $loginSession, $csrf, [
    'credential' => $newAssertion['credential'],
]));
$loginBody = json_decode((string) $loginFinish->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($loginFinish->getStatusCode() !== 200
    || ($loginBody['redirect'] ?? null) !== '/verwaltung'
    || (int) $loginSession->get('user_id') !== $userId
    || (int) $loginSession->get('auth_session_id') <= 0
    || (int) $db->fetchOne('SELECT COUNT(*) FROM user_sessions WHERE user_id = ? AND revoked_at IS NULL', [$userId]) !== 1
) {
    throw new RuntimeException('New recovery credential C could not create a normal username-first FIDO2 session.');
}
$auditText = json_encode($audit->recent(40), JSON_THROW_ON_ERROR);
$ticketHash = hash('sha256', $ticket);
foreach ([
    $ticket,
    $ticketHash,
    (string) ($options['challenge'] ?? ''),
    $response['credential'],
    $remainingEncodedId,
    ...$oldCredentialIds,
    ...$oldTotpSecrets,
] as $secretMarker) {
    if ($secretMarker === '') {
        continue;
    }
    if (str_contains($auditText, $secretMarker)) {
        throw new RuntimeException('Recovery audit contains a ticket, challenge, credential response/ID, or TOTP secret.');
    }
}
if (!str_contains($auditText, 'auth.recovery_webauthn_registered')
    || !str_contains($auditText, 'auth.recovery_webauthn_credentials_revoked')
    || !str_contains($auditText, 'auth.recovery_totp_credentials_revoked')
    || !str_contains($auditText, 'auth.recovery_activation_completed')
) {
    throw new RuntimeException('Recovery credential lifecycle audit events are incomplete.');
}

echo "Full passkey-only recovery flow test: OK\n";
