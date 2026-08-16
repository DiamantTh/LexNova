<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Laminas\Cache\Psr\SimpleCache\SimpleCacheDecorator;
use Laminas\Cache\Storage\Adapter\Memory;
use LexNova\Service\CacheBackendService;
use LexNova\Service\Fail2BanLogService;
use LexNova\Service\SystemInfoService;
use LexNova\Service\SystemSettingService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = sys_get_temp_dir() . '/lexnova-system-info-' . bin2hex(random_bytes(8));
foreach (['config', 'data', 'var/cache', 'var/log'] as $directory) {
    mkdir($root . '/' . $directory, 0700, true);
}
touch($root . '/data/install.lock');

$db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$db->executeStatement('CREATE TABLE system_settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT NOT NULL, updated_at DATETIME NOT NULL)');
$localCache = new SimpleCacheDecorator(new Memory());
$settings = new SystemSettingService($db, $localCache);
$fail2ban = new Fail2BanLogService($settings, false, $root . '/var/log/fail2ban.log');
$cache = new CacheBackendService(
    ['adapter' => 'filesystem', 'namespace' => 'lexnova', 'default_ttl' => 3600, 'password' => 'cache-secret'],
    $root,
    $localCache,
);
$service = new SystemInfoService($db, $cache, $fail2ban, [
    'app' => ['base_url' => 'https://legal.example.test', 'locale' => 'de'],
    'db' => ['driver' => 'sqlite', 'path' => $root . '/data/lexnova.sqlite', 'password' => 'db-secret'],
    'cache' => ['adapter' => 'filesystem', 'password' => 'cache-secret'],
    'security' => ['totp_app_key' => 'app-secret'],
    'session' => ['secure' => true, 'httponly' => true, 'samesite' => 'Strict'],
], $root);

$status = $service->status([
    'SERVER_SOFTWARE' => 'Apache/2.4 Test',
    'SERVER_PROTOCOL' => 'HTTP/2.0',
    'GATEWAY_INTERFACE' => 'CGI/1.1',
    'DOCUMENT_ROOT' => $root . '/httpdocs',
]);
if (!$status['database']['connected'] || $status['database']['product'] !== 'SQLite') {
    throw new RuntimeException('System information did not report the active SQLite database.');
}
if ($status['cache']['effective'] !== 'filesystem'
    || $status['cache']['key_prefix'] !== 'lexnova.<bereich>:'
    || !$status['application']['installed']
) {
    throw new RuntimeException('System information did not report the effective runtime state.');
}
if (($status['application']['frontend_build'] ?? true) !== false
    || ($status['security']['frontend_csp_without_unsafe_inline'] ?? false) !== true
    || ($status['frontend']['node_required_at_runtime'] ?? true) !== false
) {
    throw new RuntimeException('Frontend build or CSP state is not reported correctly.');
}
if ($status['host']['server_software'] !== 'Apache/2.4 Test'
    || $status['host']['os_family'] === ''
    || $status['runtime']['php_version'] !== PHP_VERSION
    || $status['runtime']['pdo_drivers'] === []
    || count($status['runtime']['cache_clients']) !== 4
    || $status['runtime']['cache_clients'][0]['name'] !== 'APCu'
    || $status['runtime']['cache_clients'][1]['name'] !== 'Laminas APCu Cache'
    || $status['runtime']['cache_clients'][2]['name'] !== 'PhpRedis'
    || $status['runtime']['cache_clients'][3]['name'] !== 'Laminas Redis Cache'
) {
    throw new RuntimeException('General host, webserver or PHP information is incomplete.');
}
if (count($status['supported']['document_cache'] ?? []) !== 3
    || !str_contains((string) $status['supported']['document_cache'][1], 'APCu')
) {
    throw new RuntimeException('Supported application cache backends are not reported completely.');
}

$extensions = [];
foreach ($status['runtime']['extensions'] as $extension) {
    $extensions[$extension['name']] = $extension;
}
foreach (['ctype', 'mbstring', 'sodium'] as $extension) {
    if (!($extensions[$extension]['available'] ?? false)) {
        throw new RuntimeException('Neither native extension nor declared polyfill is available: ' . $extension);
    }
    if (!extension_loaded($extension) && !($extensions[$extension]['fallback'] ?? false)) {
        throw new RuntimeException('System information did not identify the active polyfill: ' . $extension);
    }
}

$serialized = json_encode($status, JSON_THROW_ON_ERROR);
foreach (['db-secret', 'cache-secret', 'app-secret'] as $secret) {
    if (str_contains($serialized, $secret)) {
        throw new RuntimeException('System information exposed a configured secret.');
    }
}

unlink($root . '/data/install.lock');
rmdir($root . '/var/log');
rmdir($root . '/var/cache');
rmdir($root . '/var');
rmdir($root . '/data');
rmdir($root . '/config');
rmdir($root);

echo "System information security test: OK\n";
