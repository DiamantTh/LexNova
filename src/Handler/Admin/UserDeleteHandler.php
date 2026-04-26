<?php

declare(strict_types=1);

namespace LexNova\Handler\Admin;

use Laminas\Diactoros\Response\RedirectResponse;
use LexNova\Service\UserService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class UserDeleteHandler implements RequestHandlerInterface
{
    public function __construct(private readonly UserService $users) {}

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
