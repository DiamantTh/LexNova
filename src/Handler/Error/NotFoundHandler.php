<?php

declare(strict_types=1);

namespace LexNova\Handler\Error;

use Laminas\Diactoros\Response\HtmlResponse;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class NotFoundHandler implements RequestHandlerInterface
{
    public function __construct(private TemplateRendererInterface $renderer)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return (new HtmlResponse(
            $this->renderer->render('error::404', ['request' => $request]),
            404,
        ))->withHeader('Cache-Control', 'no-store');
    }
}
