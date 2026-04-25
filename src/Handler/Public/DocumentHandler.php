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
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $hash = (string) $request->getAttribute('hash', '');
        $type = (string) $request->getAttribute('type', 'imprint');
        $type = in_array($type, ['imprint', 'privacy'], true) ? $type : 'imprint';

        // Optional BCP 47 language tag from the URL (e.g. /hash/imprint/de)
        $langAttr = $request->getAttribute('lang');
        $language = ($langAttr !== null && $langAttr !== '') ? (string) $langAttr : null;

        $entity = $this->entities->findByHash($hash);

        if ($entity === null) {
            return new HtmlResponse(
                $this->renderer->render('public/document', [
                    'error'  => 'Entity not found.',
                    'entity' => null,
                    'doc'    => null,
                    'type'   => $type,
                    'locale' => $language,
                ]),
                404,
            );
        }

        $doc = $this->documents->findLatest((int) $entity['id'], $type, $language);

        if ($doc === null) {
            return new HtmlResponse(
                $this->renderer->render('public/document', [
                    'error'  => 'No document found for this entity.',
                    'entity' => $entity,
                    'doc'    => null,
                    'type'   => $type,
                    'locale' => $language,
                ]),
                404,
            );
        }

        return new HtmlResponse($this->renderer->render('public/document', [
            'error'  => null,
            'entity' => $entity,
            'doc'    => $doc,
            'type'   => $type,
            'locale' => $language ?? $doc['language'],
        ]));
    }
}
