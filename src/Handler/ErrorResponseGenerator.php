<?php

declare(strict_types=1);

namespace LexNova\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Called by Mezzio's ErrorHandler when an unhandled exception bubbles up.
 *
 * The class must be callable (invokable) matching the Mezzio ErrorResponseGeneratorInterface:
 *   (Throwable, ServerRequestInterface, ResponseInterface): ResponseInterface
 */
final readonly class ErrorResponseGenerator
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
    ) {}

    public function __invoke(
        Throwable              $error,
        ServerRequestInterface $request,
        ResponseInterface      $response,
    ): ResponseInterface {
        return new HtmlResponse(
            $this->renderer->render('error/500'),
            500,
        );
    }
}
