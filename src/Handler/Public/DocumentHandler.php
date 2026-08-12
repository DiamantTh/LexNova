<?php

declare(strict_types=1);

namespace LexNova\Handler\Public;

use Laminas\Diactoros\Response\HtmlResponse;
use LexNova\Service\DocumentService;
use LexNova\Service\EntityService;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class DocumentHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly EntityService $entities,
        private readonly DocumentService $documents,
        private readonly TemplateRendererInterface $renderer,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $hash = is_string($query['hash'] ?? null) ? strtolower($query['hash']) : '';
        $type = is_string($query['typ'] ?? null) ? strtolower($query['typ']) : '';

        if (!in_array($type, ['imprint', 'privacy'], true) || preg_match('/^[0-9a-f]{32}$/D', $hash) !== 1) {
            return $this->errorResponse('Invalid document type or hash.', $type, 400);
        }

        // Both values are matched against the same database row. A valid hash
        // can therefore never be used to render a different document type.
        $doc = $this->documents->findByPublicHashAndType($hash, $type);

        if ($doc === null) {
            return $this->errorResponse('Document not found.', $type, 404);
        }

        $entity = $this->entities->findById((int) $doc['entity_id']);

        if ($entity === null) {
            return $this->errorResponse('Document not found.', $type, 404);
        }

        $variants = [];
        $publicUri = $request->getUri()->withQuery('')->withFragment('');
        foreach ($this->documents->listPublicVariants((int) $entity['id'], $type) as $language => $variantHash) {
            $variants[$language] = (string) $publicUri->withQuery(http_build_query([
                'typ' => $type,
                'hash' => $variantHash,
            ]));
        }

        $canonicalUrl = (string) $publicUri->withQuery(http_build_query([
            'typ' => $type,
            'hash' => $hash,
        ]));

        return (new HtmlResponse($this->renderer->render('public/document', [
            'error' => null,
            'entity' => $entity,
            'doc' => $doc,
            'type' => $type,
            'locale' => $doc['language'],
            'canonical_url' => $canonicalUrl,
            'variants' => $variants,
        ])))->withHeader('Cache-Control', 'public, max-age=3600, stale-while-revalidate=86400');
    }

    private function errorResponse(string $message, string $type, int $status): ResponseInterface
    {
        return (new HtmlResponse(
            $this->renderer->render('public/document', [
                'error' => $message,
                'entity' => null,
                'doc' => null,
                'type' => in_array($type, ['imprint', 'privacy'], true) ? $type : 'imprint',
                'locale' => 'de',
                'variants' => [],
            ]),
            $status,
        ))->withHeader('Cache-Control', 'no-store');
    }
}
