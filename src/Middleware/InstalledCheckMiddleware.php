<?php

declare(strict_types=1);

namespace LexNova\Middleware;

use Laminas\Diactoros\Response\RedirectResponse;
use LexNova\Service\InstallService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class InstalledCheckMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly InstallService $install,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        // Let the install route through unconditionally
        if (str_starts_with($path, '/install')) {
            return $handler->handle($request);
        }

        // Redirect to installer if not yet installed
        if (!$this->install->isInstalled()) {
            return new RedirectResponse('/install');
        }

        return $handler->handle($request);
    }
}
