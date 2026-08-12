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

        $filter = new EntityInputFilter();
        $filter->setData($body);

        if (!$filter->isValid()) {
            $session->set('flash_errors', $this->messages($filter->getMessages()));
        } else {
            $values = $filter->getValues();
            $name = $values['name'];
            $contactData = $values['contact_data'];
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

    /**
     * @param  array<string, array<string, string>> $messages
     * @return list<string>
     */
    private function messages(array $messages): array
    {
        return array_merge(...array_map('array_values', array_values($messages)));
    }
}
