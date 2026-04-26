<?php

declare(strict_types=1);

namespace LexNova\Handler\Admin;

use Laminas\Diactoros\Response\RedirectResponse;
use LexNova\Service\AuditService;
use LexNova\Service\EntityService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class EntityDeleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly EntityService $entities,
        private readonly AuditService  $audit,
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

        $this->entities->delete($id);

        $this->audit->log(
            $actorId,
            (string) ($session?->get('username') ?? ''),
            'entity.delete',
            'entity:' . $id,
            null,
            $ip,
        );

        return new RedirectResponse('/admin');
    }
}

