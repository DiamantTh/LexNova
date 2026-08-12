<?php

declare(strict_types=1);

namespace LexNova\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request)
            ->withHeader('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "base-uri 'none'",
                "object-src 'none'",
                "frame-ancestors 'none'",
                "form-action 'self'",
                "img-src 'self' data:",
                "script-src 'self' 'unsafe-inline'",
                "style-src 'self' 'unsafe-inline'",
                "connect-src 'self'",
            ]))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'camera=(), geolocation=(), microphone=()');

        $path = $request->getUri()->getPath();
        if ($path === '/admin' || str_starts_with($path, '/admin/') || $path === '/install' || $path === '/install/') {
            $response = $response->withHeader('Cache-Control', 'no-store');
        }

        if ($request->getUri()->getScheme() === 'https') {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
