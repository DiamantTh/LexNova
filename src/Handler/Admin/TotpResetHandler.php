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
 * Resets (deletes all) TOTP keys for a user.
 * POST /admin/totp/reset/{id:\d+}.
 *
 * Any admin can reset any user's TOTP — use as recovery when a user has lost
 * their authenticator.
 */
final readonly class TotpResetHandler implements RequestHandlerInterface
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
        $id = (int) $request->getAttribute('id', 0);

        if (!$guard->validateToken((string) ($body['__csrf'] ?? ''))) {
            $session->set('flash_errors', ['Invalid session token.']);

            return new RedirectResponse('/admin/users');
        }

        $user = $id > 0 ? $this->users->findById($id) : null;
        if ($user !== null) {
            $actorId = (int) ($session->get('user_id') ?? 0);
            $isSelfService = $actorId === $id;
            $action = $isSelfService ? 'auth.totp.reset' : 'auth.recovery';
            $target = 'user:' . $id . '/totp:all';
            $stepUpTarget = $isSelfService ? $target : $target . '/actor:' . $actorId;
            if ($user['activation_required'] === true || !$this->policy->canResetTotp($id)) {
                $session->set('flash_errors', ['Reset would remove the last normal sign-in path. Use the authorized recovery-ticket path instead.']);

                return new RedirectResponse('/admin/users');
            }
            if (!$this->stepUp->consume($session, $action, $stepUpTarget)) {
                $session->set('flash_errors', ['Verify with your own authenticator before credential recovery.']);

                return new RedirectResponse('/admin/users');
            }
            $removed = $this->users->deleteAllTotpKeys($id);

            $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
            $this->audit->log(
                (int) ($session->get('user_id') ?? 0),
                (string) ($session->get('username') ?? ''),
                $isSelfService ? 'auth.totp_reset' : 'auth.admin_credential_recovery',
                $target,
                'removed:' . $removed,
                $ip,
                $id,
            );
            if ($isSelfService) {
                $this->sessions->revokeOtherSessions($id, (int) $session->get('auth_session_id'));
            } else {
                $this->sessions->revokeUser($id);
            }

            $session->set('flash_messages', ['All TOTP keys have been deleted for the selected user.']);
        } else {
            $session->set('flash_errors', ['User not found.']);
        }

        return new RedirectResponse('/admin/users');
    }
}
