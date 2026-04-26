<?php

declare(strict_types=1);

namespace LexNova\Handler\Admin;

use Laminas\Diactoros\Response\RedirectResponse;
use LexNova\Service\DocumentService;
use Mezzio\Csrf\CsrfMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class DocumentDeleteHandler implements RequestHandlerInterface
{
    public function __construct(private readonly DocumentService $documents) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body  = (array) ($request->getParsedBody() ?? []);

        if (!$guard->validateToken((string) ($body['__csrf'] ?? ''))) {
            return new RedirectResponse('/admin');
        }

        $id = (int) ($request->getAttribute('id') ?? 0);
        $this->documents->delete($id);

        return new RedirectResponse('/admin');
    }
}
