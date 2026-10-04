<?php

declare(strict_types=1);

namespace LexNova\Handler\Admin;

use Laminas\Diactoros\Response\RedirectResponse;
use LexNova\Service\ActivationService;
use LexNova\Service\StepUpService;
use LexNova\Service\UserService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Issues a one-time enrollment or recovery ticket for another account. */
final readonly class ActivationTicketIssueHandler implements RequestHandlerInterface
{
    public function __construct(
        private ActivationService $activation,
        private UserService $users,
        private StepUpService $stepUp,
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

            return new RedirectResponse('/admin/users');
        }

        $targetUserId = (int) ($request->getAttribute('id') ?? 0);
        $actorId = (int) ($session->get('user_id') ?? 0);
        $user = $this->users->findById($targetUserId);
        if ($user === null || $targetUserId === $actorId) {
            $session->set('flash_errors', ['A separate account must be selected for enrollment or recovery.']);

            return new RedirectResponse('/admin/users');
        }

        $target = 'user:' . $targetUserId . '/activation/actor:' . $actorId;
        if (!$this->stepUp->consume($session, 'auth.recovery', $target)) {
            $session->set('flash_errors', ['Verify with your own authenticator before issuing an enrollment or recovery ticket.']);

            return new RedirectResponse('/admin/users');
        }

        $recovery = !$user['activation_required'];
        try {
            $ticket = $this->activation->issue($targetUserId, $actorId, $recovery);
        } catch (\Throwable) {
            $session->set('flash_errors', ['Could not issue a ticket for this account.']);

            return new RedirectResponse('/admin/users');
        }

        $kind = $recovery ? 'Recovery' : 'Enrollment';
        $session->set('flash_messages', [
            "{$kind} ticket for {$user['username']} (24 hours, one-time use): {$ticket}",
        ]);

        return new RedirectResponse('/admin/users');
    }
}
