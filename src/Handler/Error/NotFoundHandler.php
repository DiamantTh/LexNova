<?php

declare(strict_types=1);

namespace LexNova\Handler\Error;

use Laminas\Diactoros\Response\HtmlResponse;
use LexNova\Frontend\SveltePageRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class NotFoundHandler implements RequestHandlerInterface
{
    public function __construct(private SveltePageRenderer $renderer)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return (new HtmlResponse(
            $this->renderer->render('not-found', [
                'path' => $request->getUri()->getPath(),
            ], 'Nicht gefunden · LexNova'),
            404,
        ))->withHeader('Cache-Control', 'no-store');
    }
}
