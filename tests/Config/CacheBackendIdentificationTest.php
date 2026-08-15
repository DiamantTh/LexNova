<?php

declare(strict_types=1);

use LexNova\Service\CacheBackendService;
use Laminas\Cache\Psr\SimpleCache\SimpleCacheDecorator;
use Laminas\Cache\Storage\Adapter\Memory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$valkey = CacheBackendService::identifyServer([
    'server_name' => 'valkey',
    'valkey_version' => '8.1.0',
    'redis_version' => '7.2.4',
]);
if ($valkey !== ['product' => 'Valkey', 'version' => '8.1.0', 'preferred' => true]) {
    throw new RuntimeException('Valkey was not preferred or was confused with its compatibility version.');
}

$redis = CacheBackendService::identifyServer(['redis_version' => '8.2.0']);
if ($redis !== ['product' => 'Redis', 'version' => '8.2.0', 'preferred' => false]) {
    throw new RuntimeException('Redis was not identified as a compatible non-preferred backend.');
}

$unknown = CacheBackendService::identifyServer([]);
if ($unknown['product'] !== 'Unbekannt' || $unknown['preferred']) {
    throw new RuntimeException('An unidentified Redis-protocol server was trusted as Valkey.');
}

$temporaryRoot = sys_get_temp_dir() . '/lexnova-cache-status-' . bin2hex(random_bytes(8));
mkdir($temporaryRoot . '/var/cache', 0700, true);
$service = new CacheBackendService(
    ['adapter' => 'filesystem', 'namespace' => 'lexnova', 'default_ttl' => 3600],
    $temporaryRoot,
    new SimpleCacheDecorator(new Memory()),
);
$status = $service->status();
if ($status['effective'] !== 'filesystem'
    || $status['product'] !== 'Dateisystem'
    || !$status['connected']
    || $status['fallback']
) {
    throw new RuntimeException('Filesystem cache status is incorrect.');
}

$documents = $service->namedCache('documents', 3600);
$settings = $service->namedCache('settings', 60);
$documents->set('same-key', 'document');
$settings->set('same-key', 'setting');
if ($documents->get('same-key') !== 'document' || $settings->get('same-key') !== 'setting') {
    throw new RuntimeException('Named cache areas are not isolated.');
}
foreach (['documents', 'settings'] as $directory) {
    $path = $temporaryRoot . '/var/cache/' . $directory;
    if (!is_dir($path) || (fileperms($path) & 0777) !== 0700) {
        throw new RuntimeException('Filesystem cache directory permissions are not restrictive.');
    }
}

$removeTree = static function (string $path): void {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
};
$removeTree($temporaryRoot . '/var/cache/documents');
$removeTree($temporaryRoot . '/var/cache/settings');

rmdir($temporaryRoot . '/var/cache');
rmdir($temporaryRoot . '/var');
rmdir($temporaryRoot);

echo "Cache backend identification test: OK\n";
