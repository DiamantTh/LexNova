<?php

declare(strict_types=1);

namespace LexNova\Handler\Auth;

use Laminas\Diactoros\Response\JsonResponse;
use LexNova\InputFilter\PasskeyCredentialInputFilter;
use LexNova\Service\AuditService;
use LexNova\Service\AuthSessionService;
use LexNova\Service\PasskeyService;
use LexNova\Service\RateLimitService;
use LexNova\Service\StepUpService;
use LexNova\Service\UserService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class PasskeyRegisterHandler implements RequestHandlerInterface
{
    public function __construct(
        private PasskeyService $passkeys,
        private UserService $users,
        private StepUpService $stepUp,
        private AuthSessionService $sessions,
        private RateLimitService $rateLimit,
        private AuditService $audit,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var SessionInterface $session */
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body = (array) ($request->getParsedBody() ?? []);
        $actorId = (int) ($session->get('user_id') ?? 0);
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!$guard->validateToken((string) ($body['__csrf'] ?? ''))) {
            return new JsonResponse(['error' => 'Invalid session token.'], 400);
        }
        if (!$this->passkeys->isConfigured()) {
            return new JsonResponse(['error' => 'Passkeys are not configured. Set app.base_url first.'], 503);
        }
        if ($this->rateLimit->isBlocked($ip, 'passkey_register')) {
            return new JsonResponse(['error' => 'Too many failed attempts.'], 429);
        }
        $user = $this->users->findById($actorId);
        if ($user === null) {
            return new JsonResponse(['error' => 'User not found.'], 404);
        }

        if (str_ends_with($request->getUri()->getPath(), '/options')) {
            if (isset($body['user_id']) && (int) $body['user_id'] !== $actorId) {
                return new JsonResponse(['error' => 'Self-service can only register an authenticator for the signed-in account.'], 403);
            }
            try {
                $hardware = ($body['mode'] ?? '') === 'hardware';
                $options = $this->passkeys->createRegistrationOptions(
                    ['id' => $actorId, 'username' => (string) $user['username']],
                    $hardware,
                );
                $session->set('passkey_registration', [
                    'options' => $options,
                    'user_id' => $actorId,
                    'mode' => $hardware ? 'cross-platform-requested' : 'any',
                    'created_at' => time(),
                ]);

                return new JsonResponse(json_decode($options, true, flags: JSON_THROW_ON_ERROR));
            } catch (\Throwable) {
                return new JsonResponse(['error' => 'Passkey registration is unavailable. Check the account limit and WebAuthn configuration.'], 400);
            }
        }

        $pending = $session->get('passkey_registration');
        $session->unset('passkey_registration');
        if (!is_array($pending)
            || time() - (int) ($pending['created_at'] ?? 0) > 120
            || (int) ($pending['user_id'] ?? 0) !== $actorId
        ) {
            return new JsonResponse(['error' => 'Passkey challenge expired or account binding failed.'], 400);
        }

        $mustStepUp = $this->users->mfaRequired($actorId)
            || $this->users->hasPasskey($actorId)
            || $this->users->hasActiveTotpKey($actorId);
        if ($mustStepUp && !$this->stepUp->consume($session, 'auth.webauthn.add', 'user:' . $actorId)) {
            return new JsonResponse(['error' => 'Verify an existing authenticator before adding another.'], 403);
        }

        $credentialInput = new PasskeyCredentialInputFilter(true);
        $body['label'] ??= 'Passkey';
        $credentialInput->setData($body);
        if (!$credentialInput->isValid()) {
            return new JsonResponse(['error' => implode(' ', $credentialInput->getErrorMessages())], 400);
        }

        try {
            $values = $credentialInput->getValues();
            $id = $this->passkeys->finishRegistration(
                $actorId,
                (string) $pending['options'],
                $values['credential'],
                $values['label'],
                $values['attachment'] !== '' ? $values['attachment'] : null,
            );
            $this->rateLimit->recordSuccess($ip, 'passkey_register');
            $session->set('auth_setup_required', false);
            $this->sessions->revokeOtherSessions($actorId, (int) $session->get('auth_session_id'));
            $this->audit->log($actorId, (string) $user['username'], 'auth.passkey_enrolled', 'user:' . $actorId, 'passkey:' . $id . ';mode:' . (string) $pending['mode'], $ip);

            return new JsonResponse(['redirect' => '/user/security', 'credential_id' => $id]);
        } catch (\Throwable) {
            $this->rateLimit->recordFailure($ip, 'passkey_register');

            return new JsonResponse(['error' => 'Passkey registration failed.'], 400);
        }
    }
}
