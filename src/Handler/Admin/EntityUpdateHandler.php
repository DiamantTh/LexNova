<?php

declare(strict_types=1);

namespace LexNova\Handler\Admin;

use Laminas\Diactoros\Response\RedirectResponse;
use LexNova\InputFilter\EntityInputFilter;
use LexNova\Service\AuditService;
use LexNova\Service\EntityService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class EntityUpdateHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly EntityService $entities,
        private readonly AuditService $audit,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id = (int) $request->getAttribute('id');
        /** @var SessionInterface $session */
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

        if ($this->entities->findById($id) === null) {
            $session->set('flash_errors', ['Entity not found.']);

            return new RedirectResponse('/admin');
        }

        if ($request->getMethod() === 'GET') {
            return new RedirectResponse('/admin?entity_id=' . $id);
        }

        // POST
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body = (array) ($request->getParsedBody() ?? []);

        if (!$guard->validateToken((string) ($body['__csrf'] ?? ''))) {
            $session->set('flash_errors', ['Invalid session token.']);

            return new RedirectResponse('/admin');
        }

        $filter = new EntityInputFilter();
        $filter->setData($body);

        if (!$filter->isValid()) {
            $messages = [];
            foreach ($filter->getMessages() as $fieldMessages) {
                $messages = array_merge($messages, array_values($fieldMessages));
            }
            $session->set('flash_errors', $messages);

            return new RedirectResponse('/admin?entity_id=' . $id);
        }

        $values = $filter->getValues();
        $name = $values['name'];
        $contactData = $values['contact_data'];
        $this->entities->update($id, $name, $contactData);

        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
        $this->audit->log(
            (int) ($session->get('user_id') ?? 0),
            (string) ($session->get('username') ?? ''),
            'entity.update',
            'entity:' . $id,
            $name,
            $ip,
        );

        $session->set('flash_messages', ['Entity updated.']);

        return new RedirectResponse('/admin');
    }
}
