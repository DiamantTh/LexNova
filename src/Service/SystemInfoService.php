<?php

declare(strict_types=1);

namespace LexNova\Service;

use Composer\InstalledVersions;
use Doctrine\DBAL\Connection;

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

    /** @return array<string, mixed> */
    public function status(): array
    {
        return [
            'application' => [
                'version' => $this->version(),
                'installed' => is_file($this->root . '/data/install.lock'),
                'base_url' => (string) ($this->config['app']['base_url'] ?? ''),
                'locale' => (string) ($this->config['app']['locale'] ?? 'de'),
            ],
            'runtime' => [
                'php_version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'architecture' => PHP_INT_SIZE * 8,
                'memory_limit' => (string) ini_get('memory_limit'),
                'opcache' => extension_loaded('Zend OPcache') && (bool) ini_get('opcache.enable'),
                'extensions' => $this->extensions(),
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
                'document_cache' => ['Dateisystem', 'Valkey/Redis-Protokoll'],
                'internal_cache' => ['Twig: Dateisystem', 'Systemeinstellungen: Dateisystem', 'HIBP: Dateisystem'],
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
                ? (string) $native->getAttribute(\PDO::ATTR_SERVER_VERSION)
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
                'host' => null,
                'port' => null,
                'name' => null,
            ];
        }
    }

    /** @return list<array{name: string, loaded: bool, version: string|null}> */
    private function extensions(): array
    {
        $extensions = ['pdo', 'intl', 'mbstring', 'openssl', 'sodium'];
        $driver = (string) ($this->config['db']['driver'] ?? '');
        if ($driver !== '') {
            $extensions[] = 'pdo_' . $driver;
        }
        if (($this->config['cache']['adapter'] ?? 'filesystem') === 'valkey') {
            $extensions[] = 'redis';
        }

        return array_map(static fn (string $extension): array => [
            'name' => $extension,
            'loaded' => extension_loaded($extension),
            'version' => extension_loaded($extension) ? phpversion($extension) ?: null : null,
        ], array_values(array_unique($extensions)));
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
}
