<?php

declare(strict_types=1);

namespace LexNova\Handler\Admin;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use LexNova\Service\DocumentService;
use LexNova\Service\EntityService;
use Locale;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class DocumentUpdateHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly EntityService $entities,
        private readonly TemplateRendererInterface $renderer,
    ) {}

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
            $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
            return new HtmlResponse($this->renderer->render('admin/document_edit', [
                'doc'        => $doc,
                'entities'   => $this->entities->list(),
                'csrf_token' => $guard->generateToken(),
                'errors'     => [],
            ]));
        }

        // POST
        $guard  = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body   = (array) ($request->getParsedBody() ?? []);

        if (!$guard->validateToken((string) ($body['__csrf'] ?? ''))) {
            $session->set('flash_errors', ['Invalid session token.']);
            return new RedirectResponse('/admin');
        }

        $entityId = (int) ($body['entity_id'] ?? 0);
        $type     = trim((string) ($body['type'] ?? ''));
        $language = trim((string) ($body['language'] ?? ''));
        $content  = trim((string) ($body['content'] ?? ''));
        $version  = trim((string) ($body['version'] ?? ''));

        $errors = [];

        if ($entityId <= 0 || $this->entities->findById($entityId) === null) {
            $errors[] = 'Please select a valid entity.';
        }

        if (!in_array($type, ['imprint', 'privacy'], true)) {
            $errors[] = 'Type must be "imprint" or "privacy".';
        }

        if ($language === '' || !$this->isValidBcp47($language)) {
            $errors[] = 'Language must be a valid BCP 47 tag (e.g. de, en-US, fr-CH).';
        }

        if ($content === '') {
            $errors[] = 'Content is required.';
        }

        if ($version === '') {
            $errors[] = 'Version is required.';
        }

        if ($errors !== []) {
            $session->set('flash_errors', $errors);
            return new RedirectResponse('/admin');
        }

        $this->documents->update($id, $entityId, $type, $language, $content, $version);
        $session->set('flash_messages', ['Document updated.']);

        return new RedirectResponse('/admin');
    }

    private function isValidBcp47(string $tag): bool
    {
        if (!preg_match('/^[a-zA-Z]{2,8}(-[a-zA-Z0-9]{1,8})*$/', $tag)) {
            return false;
        }

        $parsed = Locale::parseLocale($tag);

        return isset($parsed['language']);
    }
}
