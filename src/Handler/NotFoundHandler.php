<?php

declare(strict_types=1);

namespace LexNova\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Returns an HTML 404 response using the error/404 Twig template.
 * Registered in ContainerFactory as the Mezzio NotFoundHandler.
 */
final readonly class NotFoundHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new HtmlResponse(
            $this->renderer->render('error/404'),
            404,
        );
    }
}
