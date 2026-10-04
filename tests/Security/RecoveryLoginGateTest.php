<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use LexNova\Handler\Auth\PasskeyLoginHandler;
use LexNova\Handler\Auth\TotpVerifyHandler;
use LexNova\Service\AuditService;
use LexNova\Service\AuthSessionService;
use LexNova\Service\Fail2BanLogService;
use LexNova\Service\PasskeyService;
use LexNova\Service\PasswordService;
use LexNova\Service\RateLimitService;
use LexNova\Service\SystemSettingService;
use LexNova\Service\TotpService;
use LexNova\Service\UserService;
use Mezzio\Session\Session;
use Mezzio\Session\SessionMiddleware;
use Psr\Clock\ClockInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Mezzio\Csrf\CsrfMiddleware;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$db->executeStatement('CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL, password_hash TEXT NOT NULL,
    password_login_enabled INTEGER NOT NULL DEFAULT 1, mfa_required INTEGER NOT NULL DEFAULT 0,
    activation_required INTEGER NOT NULL DEFAULT 1, role TEXT NOT NULL DEFAULT \'admin\', created_at DATETIME NOT NULL
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
    'username' => 'recovering-user',
    'password_hash' => password_hash('irrelevant-password', PASSWORD_BCRYPT, ['cost' => 4]),
    'password_login_enabled' => 1,
    'mfa_required' => 1,
    'activation_required' => 1,
    'role' => 'admin',
    'created_at' => $now,
]);
$userId = (int) $db->lastInsertId();
$db->insert('user_authenticators', [
    'user_id' => $userId,
    'kind' => 'webauthn',
    'credential_id' => 'existing-credential-id',
    'credential_data' => 'existing-credential-data',
    'label' => 'Existing key retained during recovery',
    'created_at' => $now,
]);

$cache = new Psr16Cache(new ArrayAdapter());
$settings = new SystemSettingService($db, $cache);
$clock = new class implements ClockInterface {
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
};
$users = new UserService($db, new PasswordService([]));
$rateLimit = new RateLimitService($db, $clock);
$audit = new AuditService($db);
$sessions = new AuthSessionService($db);
$passkeys = new PasskeyService($db, 'https://lexnova.example.test', audit: $audit);
$fail2ban = new Fail2BanLogService($settings, false, sys_get_temp_dir() . '/lexnova-recovery-login-test/fail2ban.log');
$guard = new class {
    public function validateToken(string $token): bool
    {
        return hash_equals('test-csrf', $token);
    }
};
$passkeyHandler = new PasskeyLoginHandler($passkeys, $users, $sessions, $rateLimit, $audit, $fail2ban);

$request = static function (string $path, Session $session, object $csrfGuard, array $body): ServerRequest {
    return (new ServerRequest(['REMOTE_ADDR' => '192.0.2.44'], [], new Uri('https://lexnova.example.test' . $path), 'POST'))
        ->withAttribute(SessionMiddleware::SESSION_ATTRIBUTE, $session)
        ->withAttribute(CsrfMiddleware::GUARD_ATTRIBUTE, $csrfGuard)
        ->withParsedBody(['__csrf' => 'test-csrf', ...$body]);
};

// The normal username-first route refuses options for a recovery-gated account,
// even though the retained WebAuthn credential still exists.
$session = new Session([], 'recovery-login-session');
$response = $passkeyHandler->handle($request('/auth/passkey/options', $session, $guard, [
    'username' => 'recovering-user',
]));
if ($response->getStatusCode() !== 400 || $session->has('passkey_login')) {
    throw new RuntimeException('Username-first FIDO2 login remained available during recovery.');
}

// A challenge opened before recovery is also rejected before old credential
// verification can complete or create a normal session.
$session->set('passkey_login', [
    'options' => 'challenge-created-before-recovery',
    'user_id' => $userId,
    'mode' => 'primary',
    'created_at' => time(),
]);
$response = $passkeyHandler->handle($request('/auth/passkey/finish', $session, $guard, []));
if ($response->getStatusCode() !== 401 || $session->has('passkey_login')
    || $session->has('auth_session_id')
    || (int) $db->fetchOne('SELECT COUNT(*) FROM user_sessions') !== 0
) {
    throw new RuntimeException('An old FIDO2 challenge created a normal session during recovery.');
}

// Password + TOTP pending state is gated again when the second-factor endpoint
// runs, covering recovery initiated between the two login steps.
$session->set('totp_pending_user_id', $userId);
$session->set('totp_pending_created_at', time());
$totpHandler = new TotpVerifyHandler(
    new TotpService(bin2hex(random_bytes(32))),
    $users,
    $sessions,
    $rateLimit,
    $audit,
    new LexNova\Frontend\SveltePageRenderer('/missing/frontend-manifest.json'),
    $fail2ban,
);
$response = $totpHandler->handle($request('/admin/totp/verify', $session, $guard, ['code' => '12345678']));
if ($response->getStatusCode() !== 302 || $response->getHeaderLine('Location') !== '/admin/login'
    || $session->has('totp_pending_user_id')
    || (int) $db->fetchOne('SELECT COUNT(*) FROM user_sessions') !== 0
) {
    throw new RuntimeException('A pending password + TOTP flow survived recovery.');
}

echo "Recovery login gate test: OK\n";
