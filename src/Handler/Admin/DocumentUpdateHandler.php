<?php

declare(strict_types=1);

namespace LexNova\Handler\Admin;

use Laminas\Diactoros\Response\RedirectResponse;
use LexNova\InputFilter\DocumentInputFilter;
use LexNova\Service\AuditService;
use LexNova\Service\DocumentService;
use LexNova\Service\EntityService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class DocumentUpdateHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly EntityService $entities,
        private readonly AuditService $audit,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id = (int) $request->getAttribute('id');
        /** @var SessionInterface $session */
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

        $doc = $this->documents->findById($id);
        if ($doc === null) {
            $session->set('flash_errors', ['Document not found.']);

            return new RedirectResponse('/admin');
        }

        if ($request->getMethod() === 'GET') {
            return new RedirectResponse('/admin?doc_id=' . $id);
        }

        // POST
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body = (array) ($request->getParsedBody() ?? []);

        if (!$guard->validateToken((string) ($body['__csrf'] ?? ''))) {
            $session->set('flash_errors', ['Invalid session token.']);

            return new RedirectResponse('/admin');
        }

        $filter = new DocumentInputFilter();
        $filter->setData($body);

        $errors = [];

        if (!$filter->isValid()) {
            foreach ($filter->getMessages() as $fieldMessages) {
                foreach ($fieldMessages as $message) {
                    $errors[] = $message;
                }
            }
        } else {
            $values = $filter->getValues();
            $entityId = (int) $values['entity_id'];

            if ($entityId <= 0 || $this->entities->findById($entityId) === null) {
                $errors[] = 'Please select a valid entity.';
            }
        }

        if ($errors !== []) {
            $session->set('flash_errors', $errors);

            return new RedirectResponse('/admin');
        }

        $values = $filter->getValues();
        $this->documents->update($id, (int) $values['entity_id'], $values['type'], $values['language'], $values['content'], $values['version']);
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
        $this->audit->log(
            (int) ($session->get('user_id') ?? 0),
            (string) ($session->get('username') ?? ''),
            'document.update',
            'document:' . $id,
            $values['type'] . '/' . $values['language'] . ' v' . $values['version'],
            $ip,
        );
        $session->set('flash_messages', ['Document updated.']);

        return new RedirectResponse('/admin');
    }
}
