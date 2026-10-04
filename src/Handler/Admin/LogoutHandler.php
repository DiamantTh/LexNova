<?php

declare(strict_types=1);

namespace LexNova\Handler\Admin;

use Laminas\Diactoros\Response\RedirectResponse;
use LexNova\Service\AuditService;
use LexNova\Service\AuthSessionService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class LogoutHandler implements RequestHandlerInterface
{
    public function __construct(private AuthSessionService $sessions, private AuditService $audit)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body = (array) ($request->getParsedBody() ?? []);

        if ($guard->validateToken((string) ($body['__csrf'] ?? ''))) {
            /** @var SessionInterface $session */
            $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);
            $userId = (int) ($session->get('user_id') ?? 0);
            $sessionId = (int) ($session->get('auth_session_id') ?? 0);
            if ($sessionId > 0) {
                $this->sessions->revoke($sessionId);
            }
            if ($userId > 0) {
                $this->audit->log(
                    $userId,
                    (string) ($session->get('username') ?? ''),
                    'auth.logout',
                    'user:' . $userId,
                    null,
                    (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''),
                );
            }
            $session->clear();
        }

        return new RedirectResponse('/admin/login');
    }
}
