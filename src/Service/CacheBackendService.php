<?php

declare(strict_types=1);

namespace LexNova\Service;

use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Psr16Cache;

final class CacheBackendService
{
    private ?CacheInterface $cache = null;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly string $root,
        private readonly CacheInterface $statusCache,
    ) {
    }

    public function cache(): CacheInterface
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        if (($this->config['adapter'] ?? 'filesystem') === 'valkey') {
            try {
                $connection = $this->connect();

                return $this->cache = new Psr16Cache(new RedisAdapter(
                    $connection,
                    (string) ($this->config['namespace'] ?? 'lexnova'),
                    (int) ($this->config['default_ttl'] ?? 3600),
                ));
            } catch (\Throwable) {
                // Public documents must remain available if the optional backend fails.
            }
        }

        return $this->cache = $this->filesystemCache();
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
            $path = $this->root . '/var/cache/app';

            return [
                'configured' => $configured === 'filesystem' ? 'filesystem' : $configured,
                'effective' => 'filesystem',
                'product' => 'Dateisystem',
                'version' => null,
                'client' => FilesystemAdapter::class,
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
            $connection = $this->connect();
            if (!$this->probe($connection)) {
                throw new \RuntimeException('Cache backend does not accept cache operations.');
            }
            try {
                $info = $this->serverInfo($connection);
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
                'client' => get_debug_type($connection),
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
                'path' => $this->root . '/var/cache/app',
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

    private function connect(): object
    {
        $host = (string) ($this->config['host'] ?? '127.0.0.1');
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $host = '[' . $host . ']';
        }
        $username = (string) ($this->config['username'] ?? '');
        $password = (string) ($this->config['password'] ?? '');
        $auth = $username !== '' || $password !== ''
            ? rawurlencode($username) . ':' . rawurlencode($password) . '@'
            : '';
        $scheme = (bool) ($this->config['tls'] ?? false) ? 'valkeys' : 'valkey';
        $port = min(65535, max(1, (int) ($this->config['port'] ?? 6379)));
        $database = max(0, (int) ($this->config['database'] ?? 0));

        return RedisAdapter::createConnection("{$scheme}://{$auth}{$host}:{$port}/{$database}");
    }

    /** @return array<string, mixed> */
    private function serverInfo(object $connection): array
    {
        if (is_callable([$connection, 'info'])) {
            $result = call_user_func([$connection, 'info'], 'server');

            return $this->normalizeInfo($result);
        }

        if (is_callable([$connection, 'executeRaw'])) {
            $result = call_user_func([$connection, 'executeRaw'], ['INFO', 'server']);

            return $this->normalizeInfo($result);
        }

        return [];
    }

    private function probe(object $connection): bool
    {
        $cache = new Psr16Cache(new RedisAdapter(
            $connection,
            (string) ($this->config['namespace'] ?? 'lexnova'),
            10,
        ));
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

    private function filesystemCache(): CacheInterface
    {
        return new Psr16Cache(new FilesystemAdapter(
            (string) ($this->config['namespace'] ?? 'lexnova'),
            (int) ($this->config['default_ttl'] ?? 3600),
            $this->root . '/var/cache/app',
        ));
    }
}
