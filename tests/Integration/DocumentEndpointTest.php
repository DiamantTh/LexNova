<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Laminas\Cache\Psr\SimpleCache\SimpleCacheDecorator;
use Laminas\Cache\Storage\Adapter\Memory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use LexNova\Application\ContainerFactory;
use LexNova\Application\Routes;
use LexNova\Frontend\SveltePageRenderer;
use LexNova\Handler\Error\NotFoundHandler;
use LexNova\Handler\Public\DocumentHandler;
use LexNova\Service\DocumentService;
use LexNova\Service\EmailObfuscator;
use LexNova\Service\EntityService;
use LexNova\Clock\SystemClock;
use Mezzio\Application;
use Mezzio\Router\Middleware\DispatchMiddleware;
use Mezzio\Router\Middleware\RouteMiddleware;
use Mezzio\Router\RouterInterface;
use Psr\SimpleCache\CacheInterface;

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
$cache = new SimpleCacheDecorator(new Memory());
$documents = new DocumentService($db, $cache);
$documentId = $documents->create($entityId, 'imprint', 'de', 'Testinhalt', '1');
$document = $documents->findById($documentId);
$check($document !== null, 'Created document cannot be read.');
$publicHash = (string) $document['public_hash'];
$check(preg_match('/^[0-9a-f]{32}$/D', $publicHash) === 1, 'Document hash has the wrong format.');

$failingCache = new class implements CacheInterface {
    public function get(string $key, mixed $default = null): mixed
    {
        throw new RuntimeException('cache unavailable');
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        throw new RuntimeException('cache unavailable');
    }

    public function delete(string $key): bool
    {
        throw new RuntimeException('cache unavailable');
    }

    public function clear(): bool
    {
        throw new RuntimeException('cache unavailable');
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        throw new RuntimeException('cache unavailable');
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        throw new RuntimeException('cache unavailable');
    }

    public function deleteMultiple(iterable $keys): bool
    {
        throw new RuntimeException('cache unavailable');
    }

    public function has(string $key): bool
    {
        throw new RuntimeException('cache unavailable');
    }
};
$uncachedDocuments = new DocumentService($db, $failingCache);
$uncached = $uncachedDocuments->findByPublicHashAndType($publicHash, 'imprint');
$check($uncached !== null && (int) $uncached['id'] === $documentId, 'A cache outage blocked document reads.');

$renderer = new SveltePageRenderer($root . '/httpdocs/assets/app/.vite/manifest.json');
$bootstrap = static function (string $html): array {
    if (preg_match('/<script id="lexnova-bootstrap" type="application\/json">(.*?)<\/script>/s', $html, $matches) !== 1) {
        throw new RuntimeException('Svelte bootstrap data is missing.');
    }

    $data = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('Svelte bootstrap data is invalid.');
    }

    return $data;
};

$notFound = new NotFoundHandler($renderer);
$handler = new DocumentHandler(
    new EntityService($db),
    $documents,
    new EmailObfuscator(new SystemClock()),
    $renderer,
    $notFound,
    'https://legal.example.test',
);
$uri = new Uri('https://attacker-controlled.example/out.php');
$validRequest = (new ServerRequest([], [], $uri, 'GET'))->withQueryParams([
    'typ' => 'imprint',
    'hash' => $publicHash,
]);
$validResponse = $handler->handle($validRequest);
$check($validResponse->getStatusCode() === 200, 'Valid type/hash pair was not rendered.');
$validData = $bootstrap((string) $validResponse->getBody());
$check(($validData['document']['id'] ?? null) === $documentId, 'Wrong document was rendered.');
$check(!isset($validData['entity']['contact_data'], $validData['document']['content']), 'Raw public text leaked into bootstrap data.');
$check(
    html_entity_decode((string) ($validData['contactHtml'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') === 'Testweg 1',
    'Public contact obfuscation changed the visible content.',
);
$check(
    str_starts_with((string) ($validData['canonicalUrl'] ?? ''), 'https://legal.example.test/out.php?typ=imprint&hash='),
    'Canonical URL does not use the configured base URL.',
);

$wrongTypeRequest = $validRequest->withQueryParams([
    'typ' => 'privacy',
    'hash' => $publicHash,
]);
$check($handler->handle($wrongTypeRequest)->getStatusCode() === 404, 'Hash was accepted for the wrong type.');
$wrongTypeData = $bootstrap((string) $handler->handle($wrongTypeRequest)->getBody());
$check(($wrongTypeData['page'] ?? null) === 'not-found', 'Wrong document type did not use the central 404 page.');

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

$uiRoutes = [
    '/verwaltung' => 'workspace.dashboard',
    '/verwaltung/entities' => 'workspace.entities',
    '/verwaltung/documents' => 'workspace.documents',
    '/user/security' => 'user.security',
    '/admin' => 'admin.dashboard',
    '/admin/users' => 'admin.users',
    '/admin/security' => 'admin.security',
    '/admin/audit' => 'admin.audit',
    '/admin/system' => 'admin.system',
];
foreach ($uiRoutes as $path => $routeName) {
    $request = new ServerRequest([], [], new Uri('https://example.test' . $path), 'GET');
    $result = $container->get(RouterInterface::class)->match($request);
    $check($result->isSuccess(), "The UI route {$path} does not match.");
    $check($result->getMatchedRouteName() === $routeName, "The UI route {$path} matched the wrong handler.");
}

$missingUri = new Uri('https://example.test/nothing-here');
$missingRequest = new ServerRequest([], [], $missingUri, 'GET');
$missingRoute = $container->get(RouterInterface::class)->match($missingRequest);
$check($missingRoute->isFailure(), 'An unknown URL unexpectedly matched a route.');
$app->pipe(RouteMiddleware::class);
$app->pipe(DispatchMiddleware::class);
$app->pipe(NotFoundHandler::class);
$renderedNotFound = $app->handle($missingRequest);
$check($renderedNotFound->getStatusCode() === 404, 'Unknown URL did not return HTTP 404.');
$notFoundData = $bootstrap((string) $renderedNotFound->getBody());
$check(($notFoundData['page'] ?? null) === 'not-found', 'The Svelte 404 page was not rendered.');

fwrite(STDOUT, "Document endpoint integration test: OK\n");
