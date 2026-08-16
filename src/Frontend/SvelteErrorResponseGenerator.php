<?php

declare(strict_types=1);

namespace LexNova\Frontend;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Stratigility\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class SvelteErrorResponseGenerator
{
    public function __construct(private readonly SveltePageRenderer $renderer)
    {
    }

    public function __invoke(
        \Throwable $e,
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $status = Utils::getStatusCode($e, $response);

        return (new HtmlResponse($this->renderer->render('server-error', [
            'status' => $status,
            'message' => 'Die Anfrage konnte wegen eines internen Fehlers nicht verarbeitet werden.',
        ], 'Serverfehler · LexNova'), $status))->withHeader('Cache-Control', 'no-store');
    }
}
