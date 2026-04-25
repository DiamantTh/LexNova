<?php

declare(strict_types=1);

namespace LexNova\Application;

use Laminas\Stratigility\Middleware\ErrorHandler;
use LexNova\Middleware\InstalledCheckMiddleware;
use Mezzio\Application;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Handler\NotFoundHandler;
use Mezzio\MiddlewareFactory;
use Mezzio\Router\Middleware\DispatchMiddleware;
use Mezzio\Router\Middleware\RouteMiddleware;
use Mezzio\Session\SessionMiddleware;
use Psr\Container\ContainerInterface;

final class Pipeline
{
    public static function configure(Application $app, MiddlewareFactory $factory, ContainerInterface $container): void
    {
        $app->pipe(ErrorHandler::class);
        $app->pipe(InstalledCheckMiddleware::class);
        $app->pipe(RouteMiddleware::class);
        $app->pipe(SessionMiddleware::class);
        $app->pipe(CsrfMiddleware::class);
        $app->pipe(DispatchMiddleware::class);
        $app->pipe(NotFoundHandler::class);
    }
}
