<?php

declare(strict_types=1);

namespace LexNova\Handler\Auth;

use Laminas\Diactoros\Response\RedirectResponse;
use LexNova\Service\AuditService;
use LexNova\Service\AuthenticationPolicyService;
use LexNova\Service\AuthSessionService;
use LexNova\Service\PasskeyService;
use LexNova\Service\StepUpService;
use LexNova\Service\UserService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class PasskeyDeleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private PasskeyService $passkeys,
        private UserService $users,
        private AuditService $audit,
        private AuthenticationPolicyService $policy,
        private StepUpService $stepUp,
        private AuthSessionService $sessions,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var SessionInterface $session */
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body = (array) ($request->getParsedBody() ?? []);
        $userId = (int) ($request->getAttribute('userId') ?? 0);
        $redirect = (int) ($session->get('user_id') ?? 0) === $userId ? '/user/security' : '/admin/users';
        if (!$guard->validateToken((string) ($body['__csrf'] ?? ''))) {
            $session->set('flash_errors', ['Invalid session token.']);

            return new RedirectResponse($redirect);
        }

        $credentialId = (int) ($request->getAttribute('credentialId') ?? 0);
        $user = $this->users->findById($userId);
        if ($user === null || $credentialId <= 0) {
            $session->set('flash_errors', ['Passkey not found.']);

            return new RedirectResponse($redirect);
        }
        $authenticator = $this->users->findAuthenticator($credentialId, $userId);
        if ($authenticator === null || $authenticator['kind'] !== 'webauthn') {
            $session->set('flash_errors', ['Passkey not found.']);

            return new RedirectResponse($redirect);
        }
        $actorId = (int) ($session->get('user_id') ?? 0);
        $isSelfService = $actorId === $userId;
        $decision = null;
        if ($isSelfService) {
            if (!$this->policy->hasValidAuthenticationPathAfterRemoval($userId, 'webauthn', $credentialId)) {
                $session->set('flash_errors', ['Removing this key would leave no valid sign-in path. Use the authorized admin or recovery path.']);

                return new RedirectResponse($redirect);
            }
            $decision = $this->policy->credentialRemovalPolicy($userId, 'webauthn', $credentialId);
            if ($decision['allowed'] !== true) {
                $session->set('flash_errors', [(string) $decision['reason']]);

                return new RedirectResponse($redirect);
            }
        } elseif ($user['activation_required'] === true
            || !$this->policy->hasValidAuthenticationPathAfterRemoval($userId, 'webauthn', $credentialId)
        ) {
            $session->set('flash_errors', ['This removal would leave the account without a normal sign-in path. Issue or complete its recovery ticket instead.']);

            return new RedirectResponse($redirect);
        }
        $action = $isSelfService ? 'auth.webauthn.delete' : 'auth.recovery';
        $target = 'user:' . $userId . '/passkey:' . $credentialId;
        $stepUpTarget = $isSelfService ? $target : $target . '/actor:' . $actorId;
        $grant = $this->stepUp->consume($session, $action, $stepUpTarget);
        if (!$grant) {
            $session->set('flash_errors', ['Verify with your own authenticator before changing credentials.']);

            return new RedirectResponse($redirect);
        }
        if ($isSelfService && !$this->policy->acceptsRemovalProof($decision, $grant, $credentialId)) {
            $session->set('flash_errors', ['This removal must be confirmed by a different FIDO2 credential.']);

            return new RedirectResponse($redirect);
        }
        if (!$this->passkeys->deleteForUser($credentialId, $userId)) {
            $session->set('flash_errors', ['Passkey not found.']);

            return new RedirectResponse($redirect);
        }
        if ($isSelfService) {
            $this->sessions->revokeOtherSessions($userId, (int) $session->get('auth_session_id'));
        } else {
            $this->sessions->revokeUser($userId);
        }

        $this->audit->log(
            (int) ($session->get('user_id') ?? 0),
            (string) ($session->get('username') ?? ''),
            $isSelfService ? 'auth.passkey_deleted' : 'auth.admin_credential_recovery',
            $target,
            'user:' . $userId,
            (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''),
            $userId,
        );
        $session->set('flash_messages', ['Passkey deleted.']);

        return new RedirectResponse($redirect);
    }
}
