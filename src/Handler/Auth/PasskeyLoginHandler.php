<?php

declare(strict_types=1);

namespace LexNova\Handler\Auth;

use Laminas\Diactoros\Response\JsonResponse;
use LexNova\InputFilter\PasskeyCredentialInputFilter;
use LexNova\Service\AuditService;
use LexNova\Service\AuthSessionService;
use LexNova\Service\Fail2BanLogService;
use LexNova\Service\PasskeyService;
use LexNova\Service\RateLimitService;
use LexNova\Service\UserService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class PasskeyLoginHandler implements RequestHandlerInterface
{
    public function __construct(
        private PasskeyService $passkeys,
        private UserService $users,
        private AuthSessionService $sessions,
        private RateLimitService $rateLimit,
        private AuditService $audit,
        private Fail2BanLogService $fail2ban,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var SessionInterface $session */
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body = (array) ($request->getParsedBody() ?? []);
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!$guard->validateToken((string) ($body['__csrf'] ?? ''))) {
            return new JsonResponse(['error' => 'Invalid session token.'], 400);
        }
        if (!$this->passkeys->isConfigured()) {
            return new JsonResponse(['error' => 'Passkeys are not configured. Set app.base_url first.'], 503);
        }
        if ($this->rateLimit->isBlocked($ip, 'passkey')) {
            $this->fail2ban->record($ip);

            return new JsonResponse(['error' => 'Too many failed attempts.'], 429);
        }

        $isOptionsRequest = str_ends_with($request->getUri()->getPath(), '/options');
        if ($isOptionsRequest) {
            $mode = (string) ($body['mode'] ?? 'primary');
            if ($mode === 'mfa') {
                if (time() - (int) ($session->get('totp_pending_created_at') ?? 0) > 300) {
                    $session->unset('totp_pending_user_id');
                    $session->unset('totp_pending_created_at');

                    return new JsonResponse(['error' => 'The pending authentication expired. Sign in again.'], 400);
                }
                $userId = (int) ($session->get('totp_pending_user_id') ?? 0);
                $user = $userId > 0 ? $this->users->findById($userId) : null;
            } else {
                $username = trim((string) ($body['username'] ?? ''));
                $user = $username !== '' ? $this->users->findByUsername($username) : null;
                $userId = (int) ($user['id'] ?? 0);
            }
            if ($user === null || $user['activation_required'] === true || $userId <= 0 || !$this->users->hasPasskey($userId)) {
                return new JsonResponse(['error' => 'No eligible FIDO2 authenticator was found for this account.'], 400);
            }
            try {
                $options = $this->passkeys->createAuthenticationOptions([
                    'id' => $userId,
                    'username' => (string) $user['username'],
                ]);
                $session->set('passkey_login', [
                    'options' => $options,
                    'user_id' => $userId,
                    'mode' => $mode === 'mfa' ? 'mfa' : 'primary',
                    'created_at' => time(),
                ]);

                return new JsonResponse(json_decode($options, true, flags: JSON_THROW_ON_ERROR));
            } catch (\Throwable) {
                return new JsonResponse(['error' => 'FIDO2 sign-in is unavailable for this account.'], 400);
            }
        }

        $pending = $session->get('passkey_login');
        $session->unset('passkey_login');
        if (!is_array($pending) || time() - (int) ($pending['created_at'] ?? 0) > 120) {
            return new JsonResponse(['error' => 'Passkey challenge expired.'], 400);
        }
        if (($pending['mode'] ?? '') === 'mfa'
            && time() - (int) ($session->get('totp_pending_created_at') ?? 0) > 300
        ) {
            $session->unset('totp_pending_user_id');
            $session->unset('totp_pending_created_at');

            return new JsonResponse(['error' => 'The pending authentication expired. Sign in again.'], 400);
        }
        $pendingUserId = (int) ($pending['user_id'] ?? 0);
        $pendingUser = $pendingUserId > 0 ? $this->users->findById($pendingUserId) : null;
        if ($pendingUser === null || $pendingUser['activation_required'] === true) {
            $session->unset('totp_pending_user_id');
            $session->unset('totp_pending_created_at');
            $session->unset('totp_pending_mfa');

            return new JsonResponse(['error' => 'This account requires activation or recovery before sign-in.'], 401);
        }

        try {
            $input = new PasskeyCredentialInputFilter(false);
            $input->setData($body);
            if (!$input->isValid()) {
                throw new \InvalidArgumentException('Invalid FIDO2 response.');
            }
            $user = $this->passkeys->finishAuthentication(
                (string) $pending['options'],
                $input->getValues()['credential'],
                (int) $pending['user_id'],
            );
            $account = $this->users->findById((int) $user['id']);
            if ($account === null || $account['activation_required'] === true) {
                throw new \RuntimeException('Account activation or recovery is required before sign-in.');
            }
            $this->rateLimit->recordSuccess($ip, 'passkey');
            $session->regenerate();
            $session->unset('totp_pending_user_id');
            $session->unset('totp_pending_created_at');
            $session->unset('totp_pending_mfa');
            $this->sessions->establish($session, (int) $user['id'], ($pending['mode'] ?? '') === 'mfa' ? 'password+webauthn' : 'webauthn', 'uv');
            $session->set('user_id', $user['id']);
            $session->set('username', $user['username']);
            $session->set('role', $user['role']);
            $this->audit->log($user['id'], $user['username'], 'auth.passkey_success', 'user:' . $user['id'], null, $ip);

            return new JsonResponse(['redirect' => '/verwaltung']);
        } catch (\Throwable) {
            $this->rateLimit->recordFailure($ip, 'passkey');
            $this->fail2ban->record($ip);
            $this->audit->log(null, null, 'auth.passkey_failed', null, null, $ip);

            return new JsonResponse(['error' => 'Passkey authentication failed.'], 401);
        }
    }
}
