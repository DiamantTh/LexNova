<?php

declare(strict_types=1);

namespace LexNova\Handler\Public;

use Laminas\Diactoros\Response\HtmlResponse;
use LexNova\Frontend\SveltePageRenderer;
use LexNova\Handler\Error\NotFoundHandler;
use LexNova\Service\DocumentService;
use LexNova\Service\EntityService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class DocumentHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly EntityService $entities,
        private readonly DocumentService $documents,
        private readonly SveltePageRenderer $renderer,
        private readonly NotFoundHandler $notFound,
        private readonly string $baseUrl,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $hash = is_string($query['hash'] ?? null) ? strtolower($query['hash']) : '';
        $type = is_string($query['typ'] ?? null) ? strtolower($query['typ']) : '';

        if (!in_array($type, ['imprint', 'privacy'], true) || preg_match('/^[0-9a-f]{32}$/D', $hash) !== 1) {
            return $this->notFound->handle($request);
        }

        // Both values are matched against the same database row. A valid hash
        // can therefore never be used to render a different document type.
        $doc = $this->documents->findByPublicHashAndType($hash, $type);

        if ($doc === null) {
            return $this->notFound->handle($request);
        }

        $entity = $this->entities->findById((int) $doc['entity_id']);

        if ($entity === null) {
            return $this->notFound->handle($request);
        }

        $variants = [];
        foreach ($this->documents->listPublicVariants((int) $entity['id'], $type) as $language => $variantHash) {
            $variants[$language] = $this->publicUrl([
                'typ' => $type,
                'hash' => $variantHash,
            ]);
        }

        $canonicalUrl = $this->publicUrl([
            'typ' => $type,
            'hash' => $hash,
        ]);

        $locale = (string) $doc['language'];
        $title = $type === 'privacy' ? 'Datenschutzerklärung · LexNova' : 'Impressum · LexNova';

        return (new HtmlResponse($this->renderer->render('public-document', [
            'entity' => $entity,
            'document' => $doc,
            'documentType' => $type,
            'canonicalUrl' => $canonicalUrl,
            'variants' => $variants,
        ], $title, $locale)))->withHeader('Cache-Control', 'public, max-age=3600, stale-while-revalidate=86400');
    }

    /** @param array{typ: string, hash: string} $query */
    private function publicUrl(array $query): string
    {
        return rtrim($this->baseUrl, '/') . '/out.php?' . http_build_query($query);
    }
}
