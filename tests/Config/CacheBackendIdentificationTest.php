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

$optionalValkey = new CacheBackendService(
    [
        'adapter' => 'valkey',
        'host' => '127.0.0.1',
        'port' => 1,
        'namespace' => 'lexnova-optional',
        'default_ttl' => 60,
    ],
    $temporaryRoot,
    new SimpleCacheDecorator(new Memory()),
);
$fallbackCache = $optionalValkey->cache();
$fallbackStatus = $optionalValkey->status();
if (!$fallbackCache->set('fallback-probe', 'works', 60)
    || $fallbackCache->get('fallback-probe') !== 'works'
    || $fallbackStatus['effective'] !== 'filesystem'
    || !$fallbackStatus['fallback']
) {
    throw new RuntimeException('Missing or unreachable optional Valkey dependencies did not fall back safely.');
}

$optionalApcu = new CacheBackendService(
    ['adapter' => 'apcu', 'namespace' => 'lexnova.apcu-test', 'default_ttl' => 60],
    $temporaryRoot,
    new SimpleCacheDecorator(new Memory()),
);
$apcuCache = $optionalApcu->cache();
$apcuStatus = $optionalApcu->status();
if (CacheBackendService::apcuClientAvailable()) {
    if ($apcuStatus['effective'] !== 'apcu' || !$apcuStatus['connected'] || $apcuStatus['fallback']) {
        throw new RuntimeException('Available APCu was not activated.');
    }
} elseif (!$apcuCache->set('apcu-fallback-probe', 'works', 60)
    || $apcuCache->get('apcu-fallback-probe') !== 'works'
    || $apcuStatus['effective'] !== 'filesystem'
    || !$apcuStatus['fallback']
    || $apcuStatus['detection'] !== 'apcu_unavailable'
    || !is_array($apcuStatus['apcu'])
) {
    throw new RuntimeException('Unavailable APCu did not expose diagnostics and fall back safely.');
}

$blackHole = new CacheBackendService(
    ['adapter' => 'blackhole', 'namespace' => 'lexnova.test', 'default_ttl' => 60],
    $temporaryRoot,
    new SimpleCacheDecorator(new Memory()),
);
$disabledCache = $blackHole->cache();
if (!$disabledCache->set('not-stored', 'value', 60)
    || $disabledCache->get('not-stored') !== null
    || $blackHole->status()['effective'] !== 'blackhole'
) {
    throw new RuntimeException('BlackHole diagnostic mode stored cache data or was not reported.');
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
if (is_dir($temporaryRoot . '/var/cache/system-probe')) {
    $removeTree($temporaryRoot . '/var/cache/system-probe');
}

rmdir($temporaryRoot . '/var/cache');
rmdir($temporaryRoot . '/var');
rmdir($temporaryRoot);

echo "Cache backend identification test: OK\n";
