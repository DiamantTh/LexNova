<?php

declare(strict_types=1);

namespace LexNova\Handler\Auth;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use LexNova\Frontend\SveltePageRenderer;
use LexNova\InputFilter\PasskeyCredentialInputFilter;
use LexNova\Service\ActivationService;
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
        private SveltePageRenderer $renderer,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var SessionInterface $session */
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);
        $path = $request->getUri()->getPath();
        if ($path === '/activate' || $path === '/activate/') {
            return $this->page($request, $session, [], false);
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

                return $this->page($request, $session, ['This activation ticket is invalid or expired.'], false);
            }
            $tokenHash = hash('sha256', $token);
            $user = $this->activation->userForTicketHash($tokenHash);
            if ($user === null) {
                $this->rateLimit->recordFailure($ip, 'activation');

                return $this->page($request, $session, ['This activation ticket is invalid or expired.'], false);
            }
            $session->set('activation_ticket_hash', $tokenHash);
            $session->set('activation_user_id', $user['id']);
            $session->set('activation_verified_at', time());

            return $this->page($request, $session, [], true, $user['username']);
        }

        $ticketHash = (string) ($session->get('activation_ticket_hash') ?? '');
        $userId = (int) ($session->get('activation_user_id') ?? 0);
        $user = $ticketHash !== '' ? $this->activation->userForTicketHash($ticketHash) : null;
        $verifiedAt = (int) ($session->get('activation_verified_at') ?? 0);
        if ($user === null || $user['id'] !== $userId || $verifiedAt <= 0 || $verifiedAt > time() || time() - $verifiedAt > 900) {
            $session->unset('activation_ticket_hash');
            $session->unset('activation_user_id');
            $session->unset('activation_verified_at');

            return new JsonResponse(['error' => 'Activation verification expired. Enter the ticket again.'], 400);
        }

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
                return new JsonResponse(['error' => 'Enrollment challenge expired. Start again.'], 400);
            }
            $input = new PasskeyCredentialInputFilter(true);
            $body['label'] ??= 'FIDO2-Schlüssel';
            $input->setData($body);
            if (!$input->isValid()) {
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

                return new JsonResponse(['error' => 'FIDO2 activation failed. The ticket can be retried if it remains valid.'], 400);
            }

            $user = $this->users->findById($userId);
            if ($user === null) {
                return new JsonResponse(['error' => 'Enrollment completed. Sign in with the new FIDO2 credential.'], 400);
            }
            $session->unset('activation_ticket_hash');
            $session->unset('activation_user_id');
            $session->unset('activation_verified_at');
            $session->unset('activation_registration');
            $session->regenerate();
            try {
                // Only this freshly verified enrollment may create the first
                // normal session after recovery; ticket possession alone cannot.
                $this->sessions->establish($session, $userId, 'activation+webauthn', 'uv');
            } catch (\Throwable) {
                return new JsonResponse(['error' => 'Enrollment completed. Sign in with the new FIDO2 credential.'], 400);
            }
            $session->set('user_id', $userId);
            $session->set('username', $user['username']);
            $session->set('role', $user['role']);
            $session->set('auth_setup_required', false);
            $this->rateLimit->recordSuccess($ip, 'activation');

            if ($ticketPurpose === 'recovery') {
                $session->set('flash_messages', [
                    'Recovery completed. Remove any FIDO2 credentials you no longer have on the security page.',
                ]);

                return new JsonResponse(['redirect' => '/user/security']);
            }

            return new JsonResponse(['redirect' => '/verwaltung']);
        }

        return new JsonResponse(['error' => 'Not found.'], 404);
    }

    /** @param list<string> $errors */
    private function page(
        ServerRequestInterface $request,
        SessionInterface $session,
        array $errors,
        bool $verified,
        ?string $username = null,
    ): HtmlResponse {
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        if (!$verified) {
            $hash = (string) ($session->get('activation_ticket_hash') ?? '');
            $user = $hash !== '' ? $this->activation->userForTicketHash($hash) : null;
            if ($user !== null) {
                $verified = true;
                $username = $user['username'];
            }
        }

        return new HtmlResponse($this->renderer->render('activation', [
            'errors' => $errors,
            'csrfToken' => $guard->generateToken(),
            'verified' => $verified,
            'username' => $username,
            'passkeysAvailable' => $this->passkeys->isConfigured(),
        ], 'Konto aktivieren · LexNova'));
    }
}
