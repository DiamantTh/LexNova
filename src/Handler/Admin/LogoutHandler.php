<?php

declare(strict_types=1);

namespace LexNova\Handler\Admin;

use Laminas\Diactoros\Response\RedirectResponse;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class LogoutHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body = (array) ($request->getParsedBody() ?? []);

        if ($guard->validateToken((string) ($body['__csrf'] ?? ''))) {
            /** @var SessionInterface $session */
            $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);
            $session->clear();
        }

        return new RedirectResponse('/admin/login');
    }
}
