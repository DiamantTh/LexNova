<?php

declare(strict_types=1);

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use LexNova\Middleware\SecurityHeadersMiddleware;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$handler = new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        return new HtmlResponse('OK');
    }
};

$middleware = new SecurityHeadersMiddleware();
$request = new ServerRequest([], [], new Uri('https://legal.example.test/admin/login'), 'GET');
$response = $middleware->process($request, $handler);

$expected = [
    'Content-Security-Policy',
    'X-Content-Type-Options',
    'X-Frame-Options',
    'Referrer-Policy',
    'Permissions-Policy',
    'Strict-Transport-Security',
];

foreach ($expected as $header) {
    if (!$response->hasHeader($header)) {
        throw new RuntimeException("Missing security header: {$header}");
    }
}

if ($response->getHeaderLine('Cache-Control') !== 'no-store') {
    throw new RuntimeException('Administrative response is cacheable.');
}

$httpRequest = new ServerRequest([], [], new Uri('http://localhost/admin/login'), 'GET');
$httpResponse = $middleware->process($httpRequest, $handler);
if ($httpResponse->hasHeader('Strict-Transport-Security')) {
    throw new RuntimeException('HSTS was sent over a plain HTTP connection.');
}

echo "Security headers middleware test: OK\n";
