<?php

declare(strict_types=1);

namespace LexNova\Handler\Admin;

use Laminas\Diactoros\Response\RedirectResponse;
use LexNova\InputFilter\DocumentInputFilter;
use LexNova\Service\DocumentService;
use LexNova\Service\EntityService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class DocumentCreateHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly EntityService $entities,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $guard  = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body   = (array) ($request->getParsedBody() ?? []);
        /** @var SessionInterface $session */
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

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
            $values   = $filter->getValues();
            $entityId = (int) $values['entity_id'];

            if ($entityId <= 0 || $this->entities->findById($entityId) === null) {
                $errors[] = 'Please select a valid entity.';
            }
        }

        if ($errors !== []) {
            $session->set('flash_errors', $errors);
        } else {
            $values = $filter->getValues();
            $this->documents->create(
                (int) $values['entity_id'],
                $values['type'],
                $values['language'],
                $values['content'],
                $values['version'],
            );
            $session->set('flash_messages', ['Document created.']);
        }

        return new RedirectResponse('/admin');
    }
}
