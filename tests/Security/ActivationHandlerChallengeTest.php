<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Uri;
use LexNova\Frontend\SveltePageRenderer;
use LexNova\Handler\Auth\ActivationHandler;
use LexNova\Middleware\AdminAuthMiddleware;
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
$ticket = $activation->issue($userId, recovery: true);
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
    $audit,
    new SveltePageRenderer(dirname(__DIR__, 2) . '/httpdocs/assets/app/.vite/manifest.json'),
);
$session = new Session([], 'activation-challenge-session');
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

// Ticket verification creates only a browser-local recovery context, never a
// normal authenticated user or revocable application session.
$verifyResponse = $handler->handle($request('/activate/verify', $session, $guard, ['ticket' => $ticket]));
if ($verifyResponse->getStatusCode() !== 200
    || $session->get('activation_ticket_hash') !== $ticketHash
    || $session->get('activation_user_id') !== $userId
    || $session->get('activation_ticket_purpose') !== 'recovery'
    || $session->has('user_id')
    || $session->has('role')
    || $session->has('auth_session_id')
    || (int) $db->fetchOne('SELECT COUNT(*) FROM user_sessions') !== 0
) {
    throw new RuntimeException('Ticket verification created more than the restricted browser recovery context.');
}
$adminResponse = (new AdminAuthMiddleware($sessions))->process(
    $request('/admin', $session, $guard),
    new class implements Psr\Http\Server\RequestHandlerInterface {
        public function handle(Psr\Http\Message\ServerRequestInterface $request): Psr\Http\Message\ResponseInterface
        {
            return new Response();
        }
    },
);
if ($adminResponse->getStatusCode() !== 302 || $adminResponse->getHeaderLine('Location') !== '/admin/login') {
    throw new RuntimeException('A ticket-only recovery context accessed an admin endpoint.');
}
$wrongSession = new Session([], 'different-browser-session');
$wrongSessionResponse = $handler->handle($request('/activate/options', $wrongSession, $guard));
if ($wrongSessionResponse->getStatusCode() !== 400 || $wrongSession->has('activation_registration')) {
    throw new RuntimeException('The recovery context was transferable to a different browser session.');
}
$wrongUserSession = new Session([
    'activation_ticket_hash' => $ticketHash,
    'activation_user_id' => $userId + 1,
    'activation_verified_at' => time(),
    'activation_ticket_purpose' => 'recovery',
], 'wrong-recovery-user-session');
$wrongUserResponse = $handler->handle($request('/activate/options', $wrongUserSession, $guard));
if ($wrongUserResponse->getStatusCode() !== 400 || $wrongUserSession->has('activation_registration')) {
    throw new RuntimeException('The recovery ticket was not bound to its target account.');
}
$wrongPurposeSession = new Session([
    'activation_ticket_hash' => $ticketHash,
    'activation_user_id' => $userId,
    'activation_verified_at' => time(),
    'activation_ticket_purpose' => 'activation',
], 'wrong-recovery-purpose-session');
$wrongPurposeResponse = $handler->handle($request('/activate/options', $wrongPurposeSession, $guard));
if ($wrongPurposeResponse->getStatusCode() !== 400 || $wrongPurposeSession->has('activation_registration')) {
    throw new RuntimeException('The recovery ticket context was transferable to another purpose.');
}

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
$reverify = $handler->handle($request('/activate/verify', $session, $guard, ['ticket' => $ticket]));
if ($reverify->getStatusCode() !== 200 || $session->get('activation_verified_at') === null) {
    throw new RuntimeException('The ticket could not establish a fresh browser-bound recovery context.');
}
$session->set('activation_verified_at', time() - 901);
$expiredVerification = $handler->handle($request('/activate', $session, $guard));
if ($expiredVerification->getStatusCode() !== 200
    || $session->has('activation_ticket_hash')
    || $session->has('activation_user_id')
    || $session->has('activation_verified_at')
    || $session->has('activation_ticket_purpose')
) {
    throw new RuntimeException('An expired GET recovery context was not cleared.');
}

$replayedTicket = $activation->issue($userId, recovery: true);
$db->executeStatement(
    'UPDATE user_activation_tickets SET consumed_at = ? WHERE token_hash = ?',
    [gmdate('Y-m-d H:i:s'), hash('sha256', $replayedTicket)],
);
$replaySession = new Session([], 'replayed-recovery-ticket-session');
$handler->handle($request('/activate/verify', $replaySession, $guard, ['ticket' => $replayedTicket]));
$actions = array_column($audit->recent(30), 'action');
$auditText = json_encode($audit->recent(30), JSON_THROW_ON_ERROR);
if (!in_array('auth.recovery_ticket_verified', $actions, true)
    || !in_array('auth.recovery_context_created', $actions, true)
    || !in_array('auth.recovery_context_expired', $actions, true)
    || !in_array('auth.recovery_context_blocked', $actions, true)
    || !in_array('auth.recovery_ticket_rejected', $actions, true)
    || str_contains($auditText, $ticket)
    || str_contains($auditText, $replayedTicket)
    || str_contains($auditText, $ticketHash)
) {
    throw new RuntimeException('Recovery context audit is incomplete or contains ticket material.');
}

echo "Activation challenge TTL and replay test: OK\n";
