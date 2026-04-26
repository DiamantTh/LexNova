<?php

declare(strict_types=1);

namespace LexNova\Handler\Admin;

use Laminas\Diactoros\Response\RedirectResponse;
use LexNova\Service\AuditService;
use LexNova\Service\EntityService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class EntityCreateHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly EntityService $entities,
        private readonly AuditService $audit,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body = (array) ($request->getParsedBody() ?? []);
        /** @var SessionInterface $session */
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

        if (!$guard->validateToken((string) ($body['__csrf'] ?? ''))) {
            $session->set('flash_errors', ['Invalid session token.']);

            return new RedirectResponse('/admin');
        }

        $name = trim((string) ($body['name'] ?? ''));
        $contactData = trim((string) ($body['contact_data'] ?? ''));

        if ($name === '' || $contactData === '') {
            $session->set('flash_errors', ['Name and contact data are required.']);
        } else {
            $entity = $this->entities->create($name, $contactData);
            $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
            $this->audit->log(
                (int) ($session->get('user_id') ?? 0),
                (string) ($session->get('username') ?? ''),
                'entity.create',
                'entity:' . $entity['hash'],
                $name,
                $ip,
            );
            $session->set('flash_messages', ["Entity created. Hash: {$entity['hash']}"]);
        }

        return new RedirectResponse('/admin');
    }
}
