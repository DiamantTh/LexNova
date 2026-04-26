<?php

declare(strict_types=1);

namespace LexNova\Handler\Admin;

use Laminas\Diactoros\Response\RedirectResponse;
use LexNova\Service\AuditService;
use LexNova\Service\UserService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class UserDeleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly UserService   $users,
        private readonly AuditService  $audit,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body  = (array) ($request->getParsedBody() ?? []);

        if (!$guard->validateToken((string) ($body['__csrf'] ?? ''))) {
            return new RedirectResponse('/admin');
        }

        $id      = (int) ($request->getAttribute('id') ?? 0);
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

        // Prevent deleting yourself
        $actorId = (int) ($session?->get('user_id') ?? 0);
        if ($id === $actorId) {
            return new RedirectResponse('/admin');
        }

        $target = $this->users->findById($id);
        $this->users->delete($id);

        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
        $this->audit->log(
            $actorId,
            (string) ($session?->get('username') ?? ''),
            'user.delete',
            'user:' . $id . ':' . ($target['username'] ?? '?'),
            null,
            $ip,
        );

        return new RedirectResponse('/admin');
    }
}


    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body  = (array) ($request->getParsedBody() ?? []);

        if (!$guard->validateToken((string) ($body['__csrf'] ?? ''))) {
            return new RedirectResponse('/admin');
        }

        $id      = (int) ($request->getAttribute('id') ?? 0);
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

        // Prevent deleting yourself
        if ($id === (int) ($session?->get('user_id') ?? 0)) {
            return new RedirectResponse('/admin');
        }

        $this->users->delete($id);

        return new RedirectResponse('/admin');
    }
}
