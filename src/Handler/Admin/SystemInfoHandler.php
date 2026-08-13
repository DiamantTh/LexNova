<?php

declare(strict_types=1);

namespace LexNova\Handler\Admin;

use Laminas\Diactoros\Response\HtmlResponse;
use LexNova\Service\SystemInfoService;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class SystemInfoHandler implements RequestHandlerInterface
{
    public function __construct(
        private SystemInfoService $systemInfo,
        private TemplateRendererInterface $renderer,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new HtmlResponse($this->renderer->render('admin/system-info', [
            'system' => $this->systemInfo->status(),
        ]));
    }
}
