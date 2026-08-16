<?php

declare(strict_types=1);

namespace LexNova\Service;

use Composer\InstalledVersions;
use Doctrine\DBAL\Connection;
use LexNova\Handler\Install\Step\PrerequisiteCheck;

final readonly class SystemInfoService
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private Connection $db,
        private CacheBackendService $cache,
        private Fail2BanLogService $fail2ban,
        private array $config,
        private string $root,
    ) {
    }

    /**
     * @param  array<string, mixed> $serverParams
     * @return array<string, mixed>
     */
    public function status(array $serverParams = []): array
    {
        return [
            'application' => [
                'version' => $this->version(),
                'installed' => is_file($this->root . '/data/install.lock'),
                'base_url' => (string) ($this->config['app']['base_url'] ?? ''),
                'locale' => (string) ($this->config['app']['locale'] ?? 'de'),
            ],
            'host' => $this->host($serverParams),
            'runtime' => [
                'php_version' => PHP_VERSION,
                'zend_version' => zend_version(),
                'sapi' => PHP_SAPI,
                'architecture' => PHP_INT_SIZE * 8,
                'memory_limit' => (string) ini_get('memory_limit'),
                'max_execution_time' => (string) ini_get('max_execution_time'),
                'max_input_time' => (string) ini_get('max_input_time'),
                'max_input_vars' => (string) ini_get('max_input_vars'),
                'post_max_size' => (string) ini_get('post_max_size'),
                'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
                'max_file_uploads' => (string) ini_get('max_file_uploads'),
                'display_errors' => filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOL),
                'timezone' => date_default_timezone_get(),
                'ini_file' => php_ini_loaded_file() ?: null,
                'opcache' => extension_loaded('Zend OPcache') && (bool) ini_get('opcache.enable'),
                'pdo_drivers' => \PDO::getAvailableDrivers(),
                'extensions' => $this->extensions(),
                'cache_clients' => $this->cacheClients(),
                'components' => $this->components(),
            ],
            'database' => $this->database(),
            'cache' => $this->cache->status(),
            'security' => [
                'https_base_url' => str_starts_with((string) ($this->config['app']['base_url'] ?? ''), 'https://'),
                'session_secure' => (bool) ($this->config['session']['secure'] ?? true),
                'session_httponly' => (bool) ($this->config['session']['httponly'] ?? true),
                'session_samesite' => (string) ($this->config['session']['samesite'] ?? 'Strict'),
                'twig_cache' => (bool) ($this->config['twig']['cache_dir'] ?? false),
                'fail2ban' => $this->fail2ban->status(),
            ],
            'paths' => [
                ['name' => 'config/', 'path' => $this->root . '/config', 'writable' => is_writable($this->root . '/config')],
                ['name' => 'data/', 'path' => $this->root . '/data', 'writable' => is_writable($this->root . '/data')],
                ['name' => 'var/cache/', 'path' => $this->root . '/var/cache', 'writable' => is_writable($this->root . '/var/cache')],
                ['name' => 'var/log/', 'path' => $this->root . '/var/log', 'writable' => is_writable($this->root . '/var/log')],
            ],
            'supported' => [
                'database' => ['SQLite (PDO)', 'MySQL/MariaDB (PDO)', 'PostgreSQL (PDO)'],
                'document_cache' => [
                    'Laminas Dateisystem (Standard und Fallback)',
                    'Laminas APCu (optionaler lokaler Webcache)',
                    'Laminas Redis-Adapter mit PhpRedis für Valkey',
                ],
                'internal_cache' => ['Twig und Systemdiagnose: Dateisystem', 'Laminas: Dokumente, Systemeinstellungen und HIBP'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function database(): array
    {
        $configured = (string) ($this->config['db']['driver'] ?? '');
        try {
            $native = $this->db->getNativeConnection();
            $version = $native instanceof \PDO
                ? $this->pdoAttribute($native, \PDO::ATTR_SERVER_VERSION)
                : 'nicht ermittelbar';
            $clientVersion = $native instanceof \PDO
                ? $this->pdoAttribute($native, \PDO::ATTR_CLIENT_VERSION)
                : 'nicht ermittelbar';
            $product = match (true) {
                $configured === 'sqlite' => 'SQLite',
                $configured === 'pgsql' => 'PostgreSQL',
                stripos($version, 'mariadb') !== false => 'MariaDB',
                $configured === 'mysql' => 'MySQL',
                default => 'Unbekannt',
            };

            return [
                'connected' => true,
                'configured' => $configured,
                'product' => $product,
                'version' => $version,
                'client_version' => $clientVersion,
                'host' => $configured === 'sqlite'
                    ? (string) ($this->config['db']['path'] ?? '')
                    : (string) ($this->config['db']['host'] ?? ''),
                'port' => $configured === 'sqlite' ? null : (int) ($this->config['db']['port'] ?? 0),
                'name' => $configured === 'sqlite' ? null : (string) ($this->config['db']['name'] ?? ''),
            ];
        } catch (\Throwable) {
            return [
                'connected' => false,
                'configured' => $configured,
                'product' => 'Nicht erreichbar',
                'version' => null,
                'client_version' => null,
                'host' => null,
                'port' => null,
                'name' => null,
            ];
        }
    }

    /**
     * @param  array<string, mixed> $serverParams
     * @return array<string, mixed>
     */
    private function host(array $serverParams): array
    {
        $totalSpace = @disk_total_space($this->root);
        $freeSpace = @disk_free_space($this->root);
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;

        return [
            'os_family' => PHP_OS_FAMILY,
            'os' => PHP_OS,
            'kernel_release' => function_exists('php_uname') ? php_uname('r') : 'nicht ermittelbar',
            'machine' => function_exists('php_uname') ? php_uname('m') : 'nicht ermittelbar',
            'hostname' => function_exists('gethostname') ? gethostname() ?: 'nicht ermittelbar' : 'nicht ermittelbar',
            'server_software' => (string) ($serverParams['SERVER_SOFTWARE'] ?? 'nicht ermittelbar'),
            'server_protocol' => (string) ($serverParams['SERVER_PROTOCOL'] ?? 'nicht ermittelbar'),
            'gateway_interface' => (string) ($serverParams['GATEWAY_INTERFACE'] ?? PHP_SAPI),
            'document_root' => (string) ($serverParams['DOCUMENT_ROOT'] ?? $this->root . '/httpdocs'),
            'temporary_directory' => sys_get_temp_dir(),
            'disk_total' => is_float($totalSpace) ? $totalSpace : null,
            'disk_free' => is_float($freeSpace) ? $freeSpace : null,
            'load_average' => is_array($load) ? $load : [],
            'server_time_utc' => gmdate('Y-m-d H:i:s') . ' UTC',
        ];
    }

    /** @return list<array{name: string, loaded: bool, available: bool, fallback: bool, version: string|null, provider: string|null}> */
    private function extensions(): array
    {
        $extensions = ['apcu', 'ctype', 'fileinfo', 'filter', 'intl', 'json', 'mbstring', 'openssl', 'pdo', 'redis', 'sodium'];
        $driver = (string) ($this->config['db']['driver'] ?? '');
        if ($driver !== '') {
            $extensions[] = 'pdo_' . $driver;
        }

        return array_map(static function (string $extension): array {
            $loaded = extension_loaded($extension);
            $fallback = !$loaded && PrerequisiteCheck::isPolyfillAvailable($extension);
            $provider = PrerequisiteCheck::EXTENSION_POLYFILLS[$extension]['package'] ?? null;

            return [
                'name' => $extension,
                'loaded' => $loaded,
                'available' => $loaded || $fallback,
                'fallback' => $fallback,
                'version' => $loaded ? phpversion($extension) ?: null : null,
                'provider' => $fallback && $provider !== null
                    ? $provider . ' ' . (InstalledVersions::getPrettyVersion($provider) ?? 'unbekannt')
                    : null,
            ];
        }, array_values(array_unique($extensions)));
    }

    /** @return list<array{name: string, type: string, available: bool, version: string|null, priority: int}> */
    private function cacheClients(): array
    {
        return [
            [
                'name' => 'APCu',
                'type' => 'optionale PHP-Erweiterung',
                'available' => extension_loaded('apcu') && function_exists('apcu_enabled') && apcu_enabled(),
                'version' => extension_loaded('apcu') ? phpversion('apcu') ?: null : null,
                'priority' => 1,
            ],
            [
                'name' => 'Laminas APCu Cache',
                'type' => 'optionaler Composer-Adapter',
                'available' => InstalledVersions::isInstalled('laminas/laminas-cache-storage-adapter-apcu'),
                'version' => InstalledVersions::isInstalled('laminas/laminas-cache-storage-adapter-apcu')
                    ? InstalledVersions::getPrettyVersion('laminas/laminas-cache-storage-adapter-apcu')
                    : null,
                'priority' => 2,
            ],
            [
                'name' => 'PhpRedis',
                'type' => 'PHP-Erweiterung ext-redis',
                'available' => extension_loaded('redis'),
                'version' => extension_loaded('redis') ? phpversion('redis') ?: null : null,
                'priority' => 3,
            ],
            [
                'name' => 'Laminas Redis Cache',
                'type' => 'optionaler Composer-Adapter',
                'available' => InstalledVersions::isInstalled('laminas/laminas-cache-storage-adapter-redis'),
                'version' => InstalledVersions::isInstalled('laminas/laminas-cache-storage-adapter-redis')
                    ? InstalledVersions::getPrettyVersion('laminas/laminas-cache-storage-adapter-redis')
                    : null,
                'priority' => 4,
            ],
        ];
    }

    private function pdoAttribute(\PDO $pdo, int $attribute): string
    {
        try {
            return (string) $pdo->getAttribute($attribute);
        } catch (\Throwable) {
            return 'nicht ermittelbar';
        }
    }

    private function version(): string
    {
        try {
            $root = InstalledVersions::getRootPackage();

            return $root['pretty_version'];
        } catch (\Throwable) {
            return 'Entwicklungsstand';
        }
    }

    /** @return list<array{name: string, version: string}> */
    private function components(): array
    {
        $packages = [
            'mezzio/mezzio' => 'Mezzio',
            'doctrine/dbal' => 'Doctrine DBAL',
            'laminas/laminas-cache' => 'Laminas Cache',
            'laminas/laminas-cache-storage-adapter-filesystem' => 'Laminas Filesystem Cache',
            'laminas/laminas-cache-storage-adapter-apcu' => 'Laminas APCu Cache',
            'laminas/laminas-cache-storage-adapter-blackhole' => 'Laminas BlackHole Cache',
            'laminas/laminas-cache-storage-adapter-memory' => 'Laminas Memory Cache',
            'laminas/laminas-cache-storage-adapter-redis' => 'Laminas Redis Cache',
            'paragonie/sodium_compat' => 'Sodium Compat',
            'symfony/polyfill-ctype' => 'Ctype Polyfill',
            'symfony/polyfill-iconv' => 'Iconv Polyfill',
            'symfony/polyfill-mbstring' => 'Mbstring Polyfill',
            'twig/twig' => 'Twig',
            'php-di/php-di' => 'PHP-DI',
        ];
        $components = [];
        foreach ($packages as $package => $name) {
            if (InstalledVersions::isInstalled($package)) {
                $components[] = [
                    'name' => $name,
                    'version' => InstalledVersions::getPrettyVersion($package) ?? 'nicht ermittelbar',
                ];
            }
        }

        return $components;
    }
}
