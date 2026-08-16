<?php

declare(strict_types=1);

namespace LexNova\Handler\Admin;

use Laminas\Diactoros\Response\HtmlResponse;
use LexNova\Frontend\SveltePageRenderer;
use LexNova\Service\SystemInfoService;
use Mezzio\Csrf\CsrfMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class SystemInfoHandler implements RequestHandlerInterface
{
    public function __construct(
        private SystemInfoService $systemInfo,
        private SveltePageRenderer $renderer,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);

        return new HtmlResponse($this->renderer->render('system-info', [
            'system' => $this->systemInfo->status($request->getServerParams()),
            'csrfToken' => $guard->generateToken(),
        ], 'Systeminformationen · LexNova'));
    }
}
