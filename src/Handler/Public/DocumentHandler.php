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
        $hash = (string) $request->getAttribute('hash', '');
        $type = (string) $request->getAttribute('type', 'imprint');
        $type = in_array($type, ['imprint', 'privacy'], true) ? $type : 'imprint';

        // Optional BCP 47 language tag from the URL (e.g. /hash/imprint/de)
        $langAttr = $request->getAttribute('lang');
        $language = ($langAttr !== null && $langAttr !== '') ? (string) $langAttr : null;

        $uri = $request->getUri();
        $baseUrl = $uri->getScheme() . '://' . $uri->getAuthority();

        $entity = $this->entities->findByHash($hash);

        if ($entity === null) {
            return (new HtmlResponse(
                $this->renderer->render('public/document', [
                    'error' => 'Entity not found.',
                    'entity' => null,
                    'doc' => null,
                    'type' => $type,
                    'locale' => $language,
                ]),
                404,
            ))->withHeader('Cache-Control', 'no-store');
        }

        $doc = $this->documents->findLatest((int) $entity['id'], $type, $language);

        if ($doc === null) {
            return (new HtmlResponse(
                $this->renderer->render('public/document', [
                    'error' => 'No document found for this entity.',
                    'entity' => $entity,
                    'doc' => null,
                    'type' => $type,
                    'locale' => $language,
                ]),
                404,
            ))->withHeader('Cache-Control', 'no-store');
        }

        // Build canonical URL and per-language hreflang variant map
        $langVariants = $this->documents->listLanguageVariants((int) $entity['id'], $type);
        $variants = [];
        foreach ($langVariants as $lang) {
            $variants[$lang] = $baseUrl . '/' . $hash . '/' . $type . '/' . $lang;
        }

        // Canonical always points to the language-specific URL to avoid
        // duplicate content between /{hash}/{type} and /{hash}/{type}/{lang}
        $canonicalUrl = $baseUrl . '/' . $hash . '/' . $type . '/' . $doc['language'];

        return (new HtmlResponse($this->renderer->render('public/document', [
            'error' => null,
            'entity' => $entity,
            'doc' => $doc,
            'type' => $type,
            'locale' => $language ?? $doc['language'],
            'canonical_url' => $canonicalUrl,
            'variants' => $variants,
        ])))->withHeader('Cache-Control', 'public, max-age=3600, stale-while-revalidate=86400');
    }
}
