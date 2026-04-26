<?php

declare(strict_types=1);

namespace LexNova\Handler\Admin;

use Laminas\Diactoros\Response\RedirectResponse;
use LexNova\Service\AuditService;
use LexNova\Service\DocumentService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class DocumentDeleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly AuditService    $audit,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body  = (array) ($request->getParsedBody() ?? []);

        if (!$guard->validateToken((string) ($body['__csrf'] ?? ''))) {
            return new RedirectResponse('/admin');
        }

        $id      = (int) ($request->getAttribute('id') ?? 0);
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);
        $actorId = (int) ($session?->get('user_id') ?? 0);
        $ip      = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');

        $this->documents->delete($id);

        $this->audit->log(
            $actorId,
            (string) ($session?->get('username') ?? ''),
            'document.delete',
            'document:' . $id,
            null,
            $ip,
        );

        return new RedirectResponse('/admin');
    }
}

