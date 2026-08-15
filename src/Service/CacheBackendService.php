<?php

declare(strict_types=1);

namespace LexNova\Service;

use Laminas\Cache\Psr\SimpleCache\SimpleCacheDecorator;
use Laminas\Cache\Storage\Adapter\Filesystem;
use Laminas\Cache\Storage\Adapter\Redis as RedisAdapter;
use Laminas\Cache\Storage\Adapter\RedisOptions;
use Laminas\Cache\Storage\Adapter\RedisResourceManager;
use Psr\SimpleCache\CacheInterface;

final class CacheBackendService
{
    /** @var array<string, CacheInterface> */
    private array $caches = [];
    private ?\Redis $redisConnection = null;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly string $root,
        private readonly CacheInterface $statusCache,
    ) {
    }

    public function cache(): CacheInterface
    {
        return $this->namedCache('documents', max(0, (int) ($this->config['default_ttl'] ?? 3600)));
    }

    public function namedCache(string $name, int $defaultTtl): CacheInterface
    {
        $name = self::normalizeNamespace($name);
        if (isset($this->caches[$name])) {
            return $this->caches[$name];
        }

        if (($this->config['adapter'] ?? 'filesystem') === 'valkey') {
            try {
                return $this->caches[$name] = $this->valkeyCache($name, $defaultTtl);
            } catch (\Throwable) {
                // Every cache consumer must remain available if the optional backend fails.
            }
        }

        return $this->caches[$name] = $this->filesystemCache($name, $defaultTtl);
    }

    /**
     * @return array{
     *   configured: string, effective: string, product: string, version: ?string,
     *   client: string, connected: bool, fallback: bool, preferred: bool,
     *   detection: string, path: ?string, writable: ?bool, endpoint: ?string,
     *   database: ?int, tls: bool, namespace: string, default_ttl: int
     * }
     */
    public function status(): array
    {
        $statusCacheKey = 'cache_backend_status_' . hash('sha256', json_encode($this->config, JSON_THROW_ON_ERROR));
        try {
            $cached = $this->statusCache->get($statusCacheKey);
            if (is_array($cached)) {
                /** @var array{configured: string, effective: string, product: string, version: ?string, client: string, connected: bool, fallback: bool, preferred: bool, detection: string, path: ?string, writable: ?bool, endpoint: ?string, database: ?int, tls: bool, namespace: string, default_ttl: int} $cached */
                return $cached;
            }
        } catch (\Throwable) {
            // Diagnostics remain available without their cache.
        }

        $status = $this->inspect();
        try {
            $this->statusCache->set($statusCacheKey, $status, 300);
        } catch (\Throwable) {
            // Diagnostics remain available without their cache.
        }

        return $status;
    }

    /**
     * @param  array<string, mixed>                                      $info
     * @return array{product: string, version: ?string, preferred: bool}
     */
    public static function identifyServer(array $info): array
    {
        $serverName = strtolower((string) ($info['server_name'] ?? ''));
        if ($serverName === 'valkey' || isset($info['valkey_version'])) {
            return [
                'product' => 'Valkey',
                'version' => isset($info['valkey_version']) ? (string) $info['valkey_version'] : null,
                'preferred' => true,
            ];
        }

        if ($serverName === 'redis' || isset($info['redis_version'])) {
            return [
                'product' => 'Redis',
                'version' => isset($info['redis_version']) ? (string) $info['redis_version'] : null,
                'preferred' => false,
            ];
        }

        return ['product' => 'Unbekannt', 'version' => null, 'preferred' => false];
    }

    /**
     * @return array{configured: string, effective: string, product: string, version: ?string, client: string, connected: bool, fallback: bool, preferred: bool, detection: string, path: ?string, writable: ?bool, endpoint: ?string, database: ?int, tls: bool, namespace: string, default_ttl: int}
     */
    private function inspect(): array
    {
        $configured = (string) ($this->config['adapter'] ?? 'filesystem');
        if ($configured !== 'valkey') {
            $path = $this->root . '/var/cache/documents';

            return [
                'configured' => $configured === 'filesystem' ? 'filesystem' : $configured,
                'effective' => 'filesystem',
                'product' => 'Dateisystem',
                'version' => null,
                'client' => Filesystem::class,
                'connected' => true,
                'fallback' => $configured !== 'filesystem',
                'preferred' => false,
                'detection' => $configured === 'filesystem' ? 'configured' : 'unsupported_adapter',
                'path' => $path,
                'writable' => is_dir($path) ? is_writable($path) : is_writable(dirname($path)),
                'endpoint' => null,
                'database' => null,
                'tls' => false,
                'namespace' => (string) ($this->config['namespace'] ?? 'lexnova'),
                'default_ttl' => (int) ($this->config['default_ttl'] ?? 3600),
            ];
        }

        try {
            $cache = $this->valkeyCache('system-probe', 10);
            if (!$this->probe($cache)) {
                throw new \RuntimeException('Cache backend does not accept cache operations.');
            }
            try {
                $info = $this->serverInfo($this->redisConnection);
            } catch (\Throwable) {
                $info = [];
            }
            $identity = self::identifyServer($info);
            $host = (string) ($this->config['host'] ?? '127.0.0.1');
            $port = min(65535, max(1, (int) ($this->config['port'] ?? 6379)));

            return [
                'configured' => 'valkey',
                'effective' => 'redis-protocol',
                'product' => $identity['product'],
                'version' => $identity['version'],
                'client' => 'PhpRedis ' . (phpversion('redis') ?: 'Version unbekannt'),
                'connected' => true,
                'fallback' => false,
                'preferred' => $identity['preferred'],
                'detection' => $identity['product'] === 'Unbekannt' ? 'info_unavailable' : 'info_server',
                'path' => null,
                'writable' => null,
                'endpoint' => $host . ':' . $port,
                'database' => max(0, (int) ($this->config['database'] ?? 0)),
                'tls' => (bool) ($this->config['tls'] ?? false),
                'namespace' => (string) ($this->config['namespace'] ?? 'lexnova'),
                'default_ttl' => (int) ($this->config['default_ttl'] ?? 3600),
            ];
        } catch (\Throwable) {
            return [
                'configured' => 'valkey',
                'effective' => 'filesystem',
                'product' => 'Nicht erreichbar',
                'version' => null,
                'client' => 'nicht verfügbar',
                'connected' => false,
                'fallback' => true,
                'preferred' => false,
                'detection' => 'connection_failed',
                'path' => $this->root . '/var/cache/documents',
                'writable' => is_writable($this->root . '/var/cache'),
                'endpoint' => (string) ($this->config['host'] ?? '127.0.0.1') . ':'
                    . min(65535, max(1, (int) ($this->config['port'] ?? 6379))),
                'database' => max(0, (int) ($this->config['database'] ?? 0)),
                'tls' => (bool) ($this->config['tls'] ?? false),
                'namespace' => (string) ($this->config['namespace'] ?? 'lexnova'),
                'default_ttl' => (int) ($this->config['default_ttl'] ?? 3600),
            ];
        }
    }

    private function valkeyCache(string $name, int $defaultTtl): CacheInterface
    {
        $host = (string) ($this->config['host'] ?? '127.0.0.1');
        if ((bool) ($this->config['tls'] ?? false)) {
            $host = 'tls://' . $host;
        }
        $username = (string) ($this->config['username'] ?? '');
        $password = (string) ($this->config['password'] ?? '');
        $port = min(65535, max(1, (int) ($this->config['port'] ?? 6379)));
        $database = max(0, (int) ($this->config['database'] ?? 0));
        $options = new RedisOptions();
        $options->setServer(['host' => $host, 'port' => $port, 'timeout' => 2]);
        $options->setDatabase($database);
        $options->setNamespace($this->namespace($name));
        $options->setTtl(max(0, $defaultTtl));
        $options->setLibOptions([\Redis::OPT_SERIALIZER => \Redis::SERIALIZER_PHP]);
        if ($username !== '') {
            $options->setUser($username);
        }
        if ($password !== '') {
            $options->setPassword($password);
        }

        $adapter = new RedisAdapter($options);
        $resourceManager = new RedisResourceManager($options);
        $adapter->setResourceManager($resourceManager);
        $connection = $resourceManager->getResource();
        $this->redisConnection ??= $connection;

        return new SimpleCacheDecorator($adapter);
    }

    /** @return array<string, mixed> */
    private function serverInfo(?\Redis $connection): array
    {
        if (!$connection instanceof \Redis) {
            return [];
        }

        $result = $connection->info('server');

        return $this->normalizeInfo($result);
    }

    private function probe(CacheInterface $cache): bool
    {
        $key = 'system_info_probe_' . bin2hex(random_bytes(8));
        $value = bin2hex(random_bytes(8));

        try {
            return $cache->set($key, $value, 10)
                && $cache->get($key) === $value
                && $cache->delete($key);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function normalizeInfo(mixed $result): array
    {
        if (is_array($result)) {
            foreach ($result as $value) {
                if (is_array($value) && (isset($value['valkey_version']) || isset($value['redis_version']))) {
                    return $value;
                }
            }

            return $result;
        }

        if (!is_string($result)) {
            return [];
        }

        $info = [];
        foreach (preg_split('/\r?\n/', $result) ?: [] as $line) {
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $info[$key] = trim($value);
        }

        return $info;
    }

    private function filesystemCache(string $name, int $defaultTtl): CacheInterface
    {
        return self::createFilesystemCache(
            $this->root . '/var/cache/' . $name,
            $this->namespace($name),
            $defaultTtl,
        );
    }

    public static function createFilesystemCache(string $path, string $namespace, int $defaultTtl): CacheInterface
    {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new \RuntimeException('Cache directory could not be created: ' . $path);
        }
        @chmod($path, 0700);

        $adapter = new Filesystem([
            'cache_dir' => $path,
            'namespace' => self::normalizeNamespace($namespace),
            'ttl' => max(0, $defaultTtl),
            'dir_permission' => 0700,
            'file_permission' => 0600,
            'unserializable_classes' => false,
        ]);

        return new SimpleCacheDecorator($adapter);
    }

    private function namespace(string $name = ''): string
    {
        $base = self::normalizeNamespace((string) ($this->config['namespace'] ?? 'lexnova'));

        return $name === '' ? $base : self::normalizeNamespace($base . '.' . $name);
    }

    private static function normalizeNamespace(string $namespace): string
    {
        $normalized = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $namespace);

        return $normalized !== null && $normalized !== '' ? substr($normalized, 0, 128) : 'lexnova';
    }
}
