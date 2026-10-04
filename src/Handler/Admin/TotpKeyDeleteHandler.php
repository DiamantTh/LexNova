<?php

declare(strict_types=1);

namespace LexNova\Handler\Admin;

use Laminas\Diactoros\Response\RedirectResponse;
use LexNova\Service\AuditService;
use LexNova\Service\AuthenticationPolicyService;
use LexNova\Service\AuthSessionService;
use LexNova\Service\StepUpService;
use LexNova\Service\UserService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Deletes a single TOTP key belonging to a specific user.
 * POST /admin/users/{userId:\d+}/totp-keys/{keyId:\d+}/delete.
 *
 * Any admin may delete any user's key. UserService::deleteTotpKey() enforces
 * ownership by requiring both keyId and userId to match, so there is no risk
 * of deleting a key that belongs to a different user via URL manipulation.
 */
final readonly class TotpKeyDeleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly UserService $users,
        private readonly AuditService $audit,
        private readonly AuthenticationPolicyService $policy,
        private readonly StepUpService $stepUp,
        private readonly AuthSessionService $sessions,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var SessionInterface $session */
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body = (array) ($request->getParsedBody() ?? []);

        if (!$guard->validateToken((string) ($body['__csrf'] ?? ''))) {
            $session->set('flash_errors', ['Invalid session token.']);

            return new RedirectResponse('/admin');
        }

        $userId = (int) $request->getAttribute('userId', 0);
        $keyId = (int) $request->getAttribute('keyId', 0);

        if ($userId <= 0 || $keyId <= 0 || $this->users->findById($userId) === null) {
            $session->set('flash_errors', ['User or key not found.']);

            return new RedirectResponse('/admin');
        }

        $keys = $this->users->getTotpKeys($userId);
        $key = null;
        foreach ($keys as $candidate) {
            if ((int) $candidate['id'] === $keyId) {
                $key = $candidate;
                break;
            }
        }
        $actorId = (int) ($session->get('user_id') ?? 0);
        $isSelfService = $actorId === $userId;
        $action = $isSelfService ? 'auth.totp.delete' : 'auth.recovery';
        $target = 'user:' . $userId . '/totp:' . $keyId;
        $stepUpTarget = $isSelfService ? $target : $target . '/actor:' . $actorId;

        if ($key === null) {
            $session->set('flash_errors', ['TOTP credential not found.']);

            return new RedirectResponse('/admin/users');
        }
        $decision = null;
        if ($isSelfService) {
            if (!$this->policy->hasValidAuthenticationPathAfterRemoval($userId, 'totp', $keyId)) {
                $session->set('flash_errors', ['Removing this TOTP would leave no valid sign-in path. Use FIDO2 or the authorized admin/recovery path.']);

                return new RedirectResponse('/admin/users');
            }
            $decision = $this->policy->credentialRemovalPolicy($userId, 'totp', $keyId);
            if ($decision['allowed'] !== true) {
                $session->set('flash_errors', [(string) $decision['reason']]);

                return new RedirectResponse('/admin/users');
            }
        }
        $grant = $this->stepUp->consume($session, $action, $stepUpTarget);
        if (!$grant) {
            $session->set('flash_errors', ['Verify with your own authenticator before changing credentials.']);

            return new RedirectResponse('/admin/users');
        }
        if ($isSelfService && !$this->policy->acceptsRemovalProof($decision, $grant, $keyId)) {
            $session->set('flash_errors', ['Confirm with a different TOTP authenticator or use FIDO2.']);

            return new RedirectResponse('/admin/users');
        }

        $deleted = $this->users->deleteTotpKey($keyId, $userId);

        if ($deleted) {
            $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
            $this->audit->log(
                (int) ($session->get('user_id') ?? 0),
                (string) ($session->get('username') ?? ''),
                $isSelfService ? 'auth.totp_deleted' : 'auth.admin_credential_recovery',
                $target,
                'totp key removed',
                $ip,
                $userId,
            );
            if ($isSelfService) {
                $this->sessions->revokeOtherSessions($userId, (int) $session->get('auth_session_id'));
            } else {
                $this->sessions->revokeUser($userId);
            }
            $session->set('flash_messages', ['TOTP key deleted.']);
        } else {
            $session->set('flash_errors', ['Key not found or does not belong to this user.']);
        }

        return new RedirectResponse('/admin');
    }
}
