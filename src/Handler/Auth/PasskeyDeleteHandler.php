<?php

declare(strict_types=1);

namespace LexNova\Handler\Auth;

use Laminas\Diactoros\Response\RedirectResponse;
use LexNova\Service\AuditService;
use LexNova\Service\PasskeyService;
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

        $userId = (int) ($request->getAttribute('userId') ?? 0);
        $credentialId = (int) ($request->getAttribute('credentialId') ?? 0);
        $user = $this->users->findById($userId);
        if ($user === null || $credentialId <= 0) {
            $session->set('flash_errors', ['Passkey not found.']);

            return new RedirectResponse('/admin');
        }
        if ($user['password_login_enabled'] !== true && $this->users->countPasskeys($userId) <= 1) {
            $session->set('flash_errors', ['The last Passkey cannot be deleted while password login is disabled.']);

            return new RedirectResponse('/admin');
        }
        if (!$this->passkeys->deleteForUser($credentialId, $userId)) {
            $session->set('flash_errors', ['Passkey not found.']);

            return new RedirectResponse('/admin');
        }

        $this->audit->log(
            (int) ($session->get('user_id') ?? 0),
            (string) ($session->get('username') ?? ''),
            'auth.passkey_deleted',
            'passkey:' . $credentialId,
            'user:' . $userId,
            (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''),
        );
        $session->set('flash_messages', ['Passkey deleted.']);

        return new RedirectResponse('/admin');
    }
}
