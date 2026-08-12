<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use LexNova\Application\ContainerFactory;
use LexNova\Application\Routes;
use LexNova\Handler\Error\NotFoundHandler;
use LexNova\Handler\Public\DocumentHandler;
use LexNova\Service\DocumentService;
use LexNova\Service\EntityService;
use Mezzio\Application;
use Mezzio\Router\Middleware\DispatchMiddleware;
use Mezzio\Router\Middleware\RouteMiddleware;
use Mezzio\Router\RouterInterface;
use Mezzio\Template\TemplatePath;
use Mezzio\Template\TemplateRendererInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$pdo = $db->getNativeConnection();
$check($pdo instanceof PDO, 'SQLite connection is unavailable.');
$pdo->exec((string) file_get_contents($root . '/sql/schema.sqlite.sql'));

$db->insert('legal_entities', [
    'hash' => bin2hex(random_bytes(16)),
    'name' => 'Integration Test GmbH',
    'contact_data' => 'Testweg 1',
]);
$entityId = (int) $db->lastInsertId();

/** @var CacheInterface $cache */
$cache = new Psr16Cache(new ArrayAdapter());
$documents = new DocumentService($db, $cache);
$documentId = $documents->create($entityId, 'imprint', 'de', 'Testinhalt', '1');
$document = $documents->findById($documentId);
$check($document !== null, 'Created document cannot be read.');
$publicHash = (string) $document['public_hash'];
$check(preg_match('/^[0-9a-f]{32}$/D', $publicHash) === 1, 'Document hash has the wrong format.');

$renderer = new class implements TemplateRendererInterface {
    /** @var array<string, mixed> */
    public array $lastParams = [];
    public string $lastTemplate = '';

    public function render(string $name, $params = []): string
    {
        $this->lastTemplate = $name;
        $this->lastParams = (array) $params;

        return $name;
    }

    public function addPath(string $path, ?string $namespace = null): void
    {
    }

    /** @return list<TemplatePath> */
    public function getPaths(): array
    {
        return [];
    }

    public function addDefaultParam(string $templateName, string $param, mixed $value): void
    {
    }
};

$notFound = new NotFoundHandler($renderer);
$handler = new DocumentHandler(new EntityService($db), $documents, $renderer, $notFound);
$uri = new Uri('https://example.test/out.php');
$validRequest = (new ServerRequest([], [], $uri, 'GET'))->withQueryParams([
    'typ' => 'imprint',
    'hash' => $publicHash,
]);
$validResponse = $handler->handle($validRequest);
$check($validResponse->getStatusCode() === 200, 'Valid type/hash pair was not rendered.');
$check($renderer->lastParams['doc']['id'] === $documentId, 'Wrong document was rendered.');
$check(
    str_contains((string) $renderer->lastParams['canonical_url'], '/out.php?typ=imprint&hash='),
    'Canonical URL does not use out.php.',
);

$wrongTypeRequest = $validRequest->withQueryParams([
    'typ' => 'privacy',
    'hash' => $publicHash,
]);
$check($handler->handle($wrongTypeRequest)->getStatusCode() === 404, 'Hash was accepted for the wrong type.');
$check($renderer->lastTemplate === 'error::404', 'Wrong document type did not use the central 404 template.');

$invalidHashRequest = $validRequest->withQueryParams([
    'typ' => 'imprint',
    'hash' => '../config',
]);
$invalidHashResponse = $handler->handle($invalidHashRequest);
$check($invalidHashResponse->getStatusCode() === 404, 'Malformed hash did not return 404.');
$check($invalidHashResponse->getHeaderLine('Cache-Control') === 'no-store', '404 response is cacheable.');

$container = ContainerFactory::create();
/** @var Application $app */
$app = $container->get(Application::class);
Routes::configure($app);
$routeResult = $container->get(RouterInterface::class)->match(new ServerRequest([], [], $uri, 'GET'));
$check($routeResult->isSuccess(), 'The virtual /out.php route does not match.');
$check($routeResult->getMatchedRouteName() === 'document.view', 'The wrong route matched /out.php.');

$missingUri = new Uri('https://example.test/nothing-here');
$missingRequest = new ServerRequest([], [], $missingUri, 'GET');
$missingRoute = $container->get(RouterInterface::class)->match($missingRequest);
$check($missingRoute->isFailure(), 'An unknown URL unexpectedly matched a route.');
$app->pipe(RouteMiddleware::class);
$app->pipe(DispatchMiddleware::class);
$app->pipe(NotFoundHandler::class);
$renderedNotFound = $app->handle($missingRequest);
$check($renderedNotFound->getStatusCode() === 404, 'Unknown URL did not return HTTP 404.');
$check(
    str_contains((string) $renderedNotFound->getBody(), 'Hier gibt es nichts zu finden.'),
    'The styled Twig 404 template was not rendered.',
);

fwrite(STDOUT, "Document endpoint integration test: OK\n");
