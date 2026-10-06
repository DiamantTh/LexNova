<?php

declare(strict_types=1);

namespace LexNova\Handler\Auth;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use LexNova\Frontend\SveltePageRenderer;
use LexNova\InputFilter\PasskeyCredentialInputFilter;
use LexNova\Service\ActivationService;
use LexNova\Service\AuditService;
use LexNova\Service\AuthSessionService;
use LexNova\Service\PasskeyService;
use LexNova\Service\RateLimitService;
use LexNova\Service\UserService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class ActivationHandler implements RequestHandlerInterface
{
    public function __construct(
        private ActivationService $activation,
        private PasskeyService $passkeys,
        private UserService $users,
        private AuthSessionService $sessions,
        private RateLimitService $rateLimit,
        private AuditService $audit,
        private SveltePageRenderer $renderer,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var SessionInterface $session */
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);
        $path = $request->getUri()->getPath();
        if ($path === '/activate' || $path === '/activate/') {
            $context = $this->resolveActivationContext(
                $session,
                (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0'),
            );

            return $this->page($request, [], $context !== null, $context['username'] ?? null);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!$guard->validateToken((string) ($body['__csrf'] ?? ''))) {
            return new JsonResponse(['error' => 'Invalid session token.'], 400);
        }
        if ($this->rateLimit->isBlocked($ip, 'activation')) {
            return new JsonResponse(['error' => 'Too many failed attempts.'], 429);
        }

        if ($path === '/activate/verify') {
            $token = trim((string) ($body['ticket'] ?? ''));
            if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
                $this->rateLimit->recordFailure($ip, 'activation');
                $this->audit->log(null, null, 'auth.activation_ticket_rejected', null, 'reason:invalid-format', $ip);

                return $this->page($request, ['This activation ticket is invalid or expired.'], false);
            }
            $tokenHash = hash('sha256', $token);
            $user = $this->activation->userForTicketHash($tokenHash);
            if ($user === null) {
                $this->rateLimit->recordFailure($ip, 'activation');
                $context = $this->activation->ticketAuditContext($tokenHash);
                $recovery = ($context['purpose'] ?? null) === 'recovery';
                $this->audit->log(
                    null,
                    null,
                    $recovery ? 'auth.recovery_ticket_rejected' : 'auth.activation_ticket_rejected',
                    isset($context['user_id']) ? 'user:' . $context['user_id'] : null,
                    'reason:expired-revoked-or-consumed',
                    $ip,
                    $context['user_id'] ?? null,
                );

                return $this->page($request, ['This activation ticket is invalid or expired.'], false);
            }
            if ($user['purpose'] === 'recovery') {
                // A recovery browser must not retain an unrelated or stale
                // application identity while using the restricted context.
                $existingAuthSessionId = (int) ($session->get('auth_session_id') ?? 0);
                if ($existingAuthSessionId > 0) {
                    $this->sessions->revoke($existingAuthSessionId);
                }
                $session->clear();
                $session->regenerate();
            }
            $session->set('activation_ticket_hash', $tokenHash);
            $session->set('activation_user_id', $user['id']);
            $session->set('activation_verified_at', time());
            $session->set('activation_ticket_purpose', $user['purpose']);
            $recovery = $user['purpose'] === 'recovery';
            $this->audit->log(
                null,
                null,
                $recovery ? 'auth.recovery_ticket_verified' : 'auth.activation_ticket_verified',
                'user:' . $user['id'],
                'purpose:' . $user['purpose'],
                $ip,
                $user['id'],
            );
            $this->audit->log(
                null,
                null,
                $recovery ? 'auth.recovery_context_created' : 'auth.activation_context_created',
                'user:' . $user['id'],
                'ttl:900;purpose:' . $user['purpose'],
                $ip,
                $user['id'],
            );

            return $this->page($request, [], true, $user['username']);
        }

        $context = $this->resolveActivationContext($session, $ip);
        if ($context === null) {
            return new JsonResponse(['error' => 'Activation verification expired. Enter the ticket again.'], 400);
        }
        $ticketHash = (string) $session->get('activation_ticket_hash');
        $userId = (int) $context['id'];
        $user = $context;

        if ($path === '/activate/options') {
            try {
                $hardware = ($body['mode'] ?? '') === 'hardware';
                $options = $this->passkeys->createRegistrationOptions([
                    'id' => $userId,
                    'username' => $user['username'],
                ], $hardware);
                $session->set('activation_registration', [
                    'options' => $options,
                    'ticket_hash' => $ticketHash,
                    'user_id' => $userId,
                    'created_at' => time(),
                ]);

                return new JsonResponse(json_decode($options, true, flags: JSON_THROW_ON_ERROR));
            } catch (\Throwable) {
                if ($user['purpose'] === 'recovery') {
                    $this->audit->log(null, null, 'auth.recovery_enrollment_failed', 'user:' . $userId, 'reason:enrollment-options-unavailable', $ip, $userId);
                }

                return new JsonResponse(['error' => 'FIDO2 enrollment is unavailable. Check WebAuthn configuration and account limits.'], 400);
            }
        }

        if ($path === '/activate/finish') {
            $ticketPurpose = $user['purpose'];
            $pending = $session->get('activation_registration');
            $session->unset('activation_registration');
            $challengeCreatedAt = is_array($pending) ? (int) ($pending['created_at'] ?? 0) : 0;
            if (!is_array($pending)
                || $challengeCreatedAt <= 0
                || $challengeCreatedAt > time()
                || time() - $challengeCreatedAt > 120
                || (int) ($pending['user_id'] ?? 0) !== $userId
                || !hash_equals((string) ($pending['ticket_hash'] ?? ''), $ticketHash)
            ) {
                if ($ticketPurpose === 'recovery') {
                    $this->audit->log(
                        null,
                        null,
                        'auth.recovery_context_blocked',
                        'user:' . $userId,
                        'reason:enrollment-challenge-expired-or-binding-mismatch',
                        $ip,
                        $userId,
                    );
                }

                return new JsonResponse(['error' => 'Enrollment challenge expired. Start again.'], 400);
            }
            $input = new PasskeyCredentialInputFilter(true);
            $body['label'] ??= 'FIDO2-Schlüssel';
            $input->setData($body);
            if (!$input->isValid()) {
                if ($ticketPurpose === 'recovery') {
                    $this->audit->log(null, null, 'auth.recovery_enrollment_failed', 'user:' . $userId, 'reason:invalid-registration-response', $ip, $userId);
                }

                return new JsonResponse(['error' => 'Invalid FIDO2 registration response.'], 400);
            }

            try {
                $values = $input->getValues();
                $this->activation->complete($userId, $ticketHash, fn (): int => $this->passkeys->finishRegistration(
                    $userId,
                    (string) $pending['options'],
                    $values['credential'],
                    $values['label'],
                    $values['attachment'] !== '' ? $values['attachment'] : null,
                ), $ip);
            } catch (\Throwable) {
                $this->rateLimit->recordFailure($ip, 'activation');
                if ($ticketPurpose === 'recovery') {
                    $this->audit->log(null, null, 'auth.recovery_enrollment_failed', 'user:' . $userId, 'reason:verification-persistence-or-cleanup-failed', $ip, $userId);
                }

                return new JsonResponse(['error' => 'FIDO2 activation failed. The ticket can be retried if it remains valid.'], 400);
            }

            $this->rateLimit->recordSuccess($ip, 'activation');
            if ($ticketPurpose === 'recovery') {
                $currentSessionId = (int) ($session->get('auth_session_id') ?? 0);
                if ($currentSessionId > 0) {
                    $this->sessions->revoke($currentSessionId);
                }
                $this->clearActivationContext($session);
                $session->clear();
                $session->regenerate();
                $session->set('flash_messages', [
                    'Recovery abgeschlossen. Bitte melde dich mit dem neu registrierten FIDO2-Schlüssel an.',
                ]);

                return new JsonResponse(['redirect' => '/admin/login']);
            }

            $user = $this->users->findById($userId);
            if ($user === null) {
                return new JsonResponse(['error' => 'Enrollment completed. Sign in with the new FIDO2 credential.'], 400);
            }
            $this->clearActivationContext($session);
            $session->regenerate();
            try {
                // First activation keeps its existing completed-enrollment
                // sign-in flow. Recovery returns anonymously above.
                $this->sessions->establish($session, $userId, 'activation+webauthn', 'uv');
            } catch (\Throwable) {
                return new JsonResponse(['error' => 'Enrollment completed. Sign in with the new FIDO2 credential.'], 400);
            }
            $session->set('user_id', $userId);
            $session->set('username', $user['username']);
            $session->set('role', $user['role']);
            $session->set('auth_setup_required', false);

            return new JsonResponse(['redirect' => '/verwaltung']);
        }

        return new JsonResponse(['error' => 'Not found.'], 404);
    }

    private function clearActivationContext(SessionInterface $session): void
    {
        $session->unset('activation_ticket_hash');
        $session->unset('activation_user_id');
        $session->unset('activation_verified_at');
        $session->unset('activation_ticket_purpose');
        $session->unset('activation_registration');
    }

    /** @return array{id: int, username: string, purpose: string}|null */
    private function resolveActivationContext(SessionInterface $session, string $ip): ?array
    {
        $ticketHash = (string) ($session->get('activation_ticket_hash') ?? '');
        $userId = (int) ($session->get('activation_user_id') ?? 0);
        $user = $ticketHash !== '' ? $this->activation->userForTicketHash($ticketHash) : null;
        $verifiedAt = (int) ($session->get('activation_verified_at') ?? 0);
        $sessionPurpose = (string) ($session->get('activation_ticket_purpose') ?? '');
        if ($user !== null && $sessionPurpose === '') {
            // Preserve an already verified pre-deployment browser context;
            // the server-side ticket hash remains the purpose source.
            $sessionPurpose = $user['purpose'];
            $session->set('activation_ticket_purpose', $sessionPurpose);
        }
        $contextPurpose = $sessionPurpose === 'recovery' || ($user['purpose'] ?? null) === 'recovery';
        $timeInvalid = $verifiedAt <= 0 || $verifiedAt > time() || time() - $verifiedAt > 900;
        if ($user !== null
            && $user['id'] === $userId
            && $sessionPurpose === $user['purpose']
            && !$timeInvalid
        ) {
            return $user;
        }

        if ($contextPurpose) {
            $action = $timeInvalid ? 'auth.recovery_context_expired' : 'auth.recovery_context_blocked';
            $reason = $timeInvalid ? 'verification-expired' : ($user === null ? 'ticket-unusable' : 'binding-mismatch');
            $this->audit->log(
                null,
                null,
                $action,
                $userId > 0 ? 'user:' . $userId : null,
                'reason:' . $reason,
                $ip,
                $userId > 0 ? $userId : null,
            );
        }
        $this->clearActivationContext($session);

        return null;
    }

    /** @param list<string> $errors */
    private function page(
        ServerRequestInterface $request,
        array $errors,
        bool $verified,
        ?string $username = null,
    ): HtmlResponse {
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);

        return new HtmlResponse($this->renderer->render('activation', [
            'errors' => $errors,
            'csrfToken' => $guard->generateToken(),
            'verified' => $verified,
            'username' => $username,
            'passkeysAvailable' => $this->passkeys->isConfigured(),
        ], 'Konto aktivieren · LexNova'));
    }
}
