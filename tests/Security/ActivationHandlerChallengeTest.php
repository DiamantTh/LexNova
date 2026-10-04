<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use LexNova\Frontend\SveltePageRenderer;
use LexNova\Handler\Auth\ActivationHandler;
use LexNova\Service\ActivationService;
use LexNova\Service\AuditService;
use LexNova\Service\AuthSessionService;
use LexNova\Service\PasskeyService;
use LexNova\Service\PasswordService;
use LexNova\Service\RateLimitService;
use LexNova\Service\UserService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\Session;
use Mezzio\Session\SessionMiddleware;
use Psr\Clock\ClockInterface;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$db->executeStatement('CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL, password_hash TEXT NOT NULL,
    password_login_enabled INTEGER NOT NULL DEFAULT 0, activation_required INTEGER NOT NULL DEFAULT 1,
    mfa_required INTEGER NOT NULL DEFAULT 0, role TEXT NOT NULL DEFAULT \'admin\', created_at DATETIME NOT NULL
)');
$db->executeStatement('CREATE TABLE user_activation_tickets (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE,
    purpose VARCHAR(20) NOT NULL DEFAULT \'activation\', created_by_user_id INTEGER NULL,
    created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, consumed_at DATETIME NULL, revoked_at DATETIME NULL
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
    'username' => 'activation-user',
    'password_hash' => password_hash('unused', PASSWORD_BCRYPT, ['cost' => 4]),
    'password_login_enabled' => 0,
    'activation_required' => 1,
    'mfa_required' => 0,
    'role' => 'admin',
    'created_at' => $now,
]);
$userId = (int) $db->lastInsertId();
$audit = new AuditService($db);
$sessions = new AuthSessionService($db);
$activation = new ActivationService($db, $audit, $sessions);
$ticket = $activation->issue($userId);
$ticketHash = hash('sha256', $ticket);
$clock = new class implements ClockInterface {
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
};
$rateLimit = new RateLimitService($db, $clock);
$passkeys = new PasskeyService($db, 'https://lexnova.example.test', audit: $audit);
$handler = new ActivationHandler(
    $activation,
    $passkeys,
    new UserService($db, new PasswordService([])),
    $sessions,
    $rateLimit,
    new SveltePageRenderer('/missing/frontend-manifest.json'),
);
$session = new Session([
    'activation_ticket_hash' => $ticketHash,
    'activation_user_id' => $userId,
    'activation_verified_at' => time(),
], 'activation-challenge-session');
$guard = new class {
    public function validateToken(string $token): bool
    {
        return hash_equals('test-csrf', $token);
    }

    public function generateToken(): string
    {
        return 'test-csrf';
    }
};
$request = static function (string $path, Session $session, object $csrfGuard, array $body = []): ServerRequest {
    return (new ServerRequest(['REMOTE_ADDR' => '192.0.2.45'], [], new Uri('https://lexnova.example.test' . $path), 'POST'))
        ->withAttribute(SessionMiddleware::SESSION_ATTRIBUTE, $session)
        ->withAttribute(CsrfMiddleware::GUARD_ATTRIBUTE, $csrfGuard)
        ->withParsedBody(['__csrf' => 'test-csrf', ...$body]);
};

// A failed enrollment consumes the in-session WebAuthn ceremony. Replaying it
// cannot reuse the challenge, and a new ceremony expires after 120 seconds.
$optionsResponse = $handler->handle($request('/activate/options', $session, $guard));
if ($optionsResponse->getStatusCode() !== 200 || !is_array($session->get('activation_registration'))) {
    throw new RuntimeException('Activation did not create the pending WebAuthn enrollment.');
}
$firstFinish = $handler->handle($request('/activate/finish', $session, $guard));
$replayedFinish = $handler->handle($request('/activate/finish', $session, $guard));
if ($firstFinish->getStatusCode() !== 400 || $replayedFinish->getStatusCode() !== 400
    || $session->has('activation_registration')
    || (int) $db->fetchOne('SELECT activation_required FROM users WHERE id = ?', [$userId]) !== 1
    || (int) $db->fetchOne('SELECT COUNT(*) FROM user_authenticators WHERE user_id = ?', [$userId]) !== 0
) {
    throw new RuntimeException('An incomplete or replayed enrollment completed activation.');
}
$handler->handle($request('/activate/options', $session, $guard));
$pending = $session->get('activation_registration');
$pending['created_at'] = time() - 121;
$session->set('activation_registration', $pending);
$expiredFinish = $handler->handle($request('/activate/finish', $session, $guard));
if ($expiredFinish->getStatusCode() !== 400 || $session->has('activation_registration')) {
    throw new RuntimeException('An expired WebAuthn enrollment challenge remained usable.');
}
$handler->handle($request('/activate/options', $session, $guard));
$futurePending = $session->get('activation_registration');
$futurePending['created_at'] = time() + 30;
$session->set('activation_registration', $futurePending);
$futureFinish = $handler->handle($request('/activate/finish', $session, $guard));
if ($futureFinish->getStatusCode() !== 400 || $session->has('activation_registration')) {
    throw new RuntimeException('A future-dated WebAuthn enrollment challenge was accepted.');
}

// The verified-ticket browser binding has its own 15-minute expiry.
$session->set('activation_verified_at', time() - 901);
$expiredVerification = $handler->handle($request('/activate/options', $session, $guard));
if ($expiredVerification->getStatusCode() !== 400
    || $session->has('activation_ticket_hash')
    || $session->has('activation_user_id')
    || $session->has('activation_verified_at')
) {
    throw new RuntimeException('Expired activation-ticket verification was not cleared.');
}

echo "Activation challenge TTL and replay test: OK\n";
