<?php

declare(strict_types=1);

namespace LexNova\Service;

use Laminas\Cache\Psr\SimpleCache\SimpleCacheDecorator;
use Laminas\Cache\Storage\Adapter\Filesystem;
use Laminas\Cache\Storage\StorageInterface;
use Psr\SimpleCache\CacheInterface;

final class CacheBackendService
{
    private const LAMINAS_APCU_ADAPTER = 'Laminas\\Cache\\Storage\\Adapter\\Apcu';
    private const LAMINAS_BLACKHOLE_ADAPTER = 'Laminas\\Cache\\Storage\\Adapter\\BlackHole';
    private const LAMINAS_REDIS_ADAPTER = 'Laminas\\Cache\\Storage\\Adapter\\Redis';
    private const LAMINAS_REDIS_OPTIONS = 'Laminas\\Cache\\Storage\\Adapter\\RedisOptions';
    private const LAMINAS_REDIS_RESOURCE_MANAGER = 'Laminas\\Cache\\Storage\\Adapter\\RedisResourceManager';

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

        $adapter = (string) ($this->config['adapter'] ?? 'filesystem');
        if ($adapter === 'apcu') {
            try {
                return $this->caches[$name] = $this->apcuCache($name, $defaultTtl);
            } catch (\Throwable) {
                // Every cache consumer must remain available if the optional backend fails.
            }
        } elseif ($adapter === 'valkey') {
            try {
                return $this->caches[$name] = $this->valkeyCache($name, $defaultTtl);
            } catch (\Throwable) {
                // Every cache consumer must remain available if the optional backend fails.
            }
        } elseif ($adapter === 'blackhole') {
            try {
                return $this->caches[$name] = $this->blackHoleCache();
            } catch (\Throwable) {
                // BlackHole is a development-only dependency; production remains operational.
            }
        }

        return $this->caches[$name] = $this->filesystemCache($name, $defaultTtl);
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $statusCacheKey = 'cache_backend_status_' . hash('sha256', json_encode($this->config, JSON_THROW_ON_ERROR));
        try {
            $cached = $this->statusCache->get($statusCacheKey);
            if (is_array($cached)) {
                /** @var array<string, mixed> $cached */
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

    /** @return array<string, mixed> */
    private function inspect(): array
    {
        $configured = (string) ($this->config['adapter'] ?? 'filesystem');
        if ($configured === 'apcu') {
            return $this->inspectApcu();
        }
        if ($configured === 'blackhole') {
            return $this->inspectBlackHole();
        }
        if ($configured !== 'valkey') {
            return $this->filesystemStatus($configured);
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
                'key_prefix' => (string) ($this->config['namespace'] ?? 'lexnova') . '.<bereich>:',
                'default_ttl' => (int) ($this->config['default_ttl'] ?? 3600),
                'apcu' => null,
            ];
        } catch (\Throwable) {
            $clientAvailable = self::valkeyClientAvailable();

            return [
                'configured' => 'valkey',
                'effective' => 'filesystem',
                'product' => 'Nicht erreichbar',
                'version' => null,
                'client' => $clientAvailable ? 'PhpRedis-Verbindung fehlgeschlagen' : 'PhpRedis/Laminas-Adapter fehlt',
                'connected' => false,
                'fallback' => true,
                'preferred' => false,
                'detection' => $clientAvailable ? 'connection_failed' : 'client_unavailable',
                'path' => $this->root . '/var/cache/documents',
                'writable' => is_writable($this->root . '/var/cache'),
                'endpoint' => (string) ($this->config['host'] ?? '127.0.0.1') . ':'
                    . min(65535, max(1, (int) ($this->config['port'] ?? 6379))),
                'database' => max(0, (int) ($this->config['database'] ?? 0)),
                'tls' => (bool) ($this->config['tls'] ?? false),
                'namespace' => (string) ($this->config['namespace'] ?? 'lexnova'),
                'key_prefix' => (string) ($this->config['namespace'] ?? 'lexnova') . '.<bereich>:',
                'default_ttl' => (int) ($this->config['default_ttl'] ?? 3600),
                'apcu' => null,
            ];
        }
    }

    /** @return array<string, mixed> */
    private function filesystemStatus(string $configured, ?string $reason = null): array
    {
        $path = $this->root . '/var/cache/documents';

        return [
            'configured' => $configured,
            'effective' => 'filesystem',
            'product' => match ($configured) {
                'apcu' => 'APCu nicht verfügbar',
                'blackhole' => 'BlackHole nicht verfügbar',
                default => 'Dateisystem',
            },
            'version' => null,
            'client' => Filesystem::class,
            'connected' => $configured === 'filesystem',
            'fallback' => $configured !== 'filesystem',
            'preferred' => false,
            'detection' => $configured === 'filesystem' ? 'configured' : ($reason ?? 'unsupported_adapter'),
            'path' => $path,
            'writable' => is_dir($path) ? is_writable($path) : is_writable(dirname($path)),
            'endpoint' => null,
            'database' => null,
            'tls' => false,
            'namespace' => (string) ($this->config['namespace'] ?? 'lexnova'),
            'key_prefix' => (string) ($this->config['namespace'] ?? 'lexnova') . '.<bereich>:',
            'default_ttl' => (int) ($this->config['default_ttl'] ?? 3600),
            'apcu' => $configured === 'apcu' ? $this->apcuDiagnostics() : null,
        ];
    }

    /** @return array<string, mixed> */
    private function inspectApcu(): array
    {
        $diagnostics = $this->apcuDiagnostics();
        try {
            $cache = $this->apcuCache('system-probe', 10);
            if (!$this->probe($cache)) {
                throw new \RuntimeException('APCu does not accept cache operations in the current SAPI.');
            }

            return [
                'configured' => 'apcu',
                'effective' => 'apcu',
                'product' => 'APCu',
                'version' => phpversion('apcu') ?: null,
                'client' => self::LAMINAS_APCU_ADAPTER,
                'connected' => true,
                'fallback' => false,
                'preferred' => true,
                'detection' => 'current_sapi',
                'path' => null,
                'writable' => null,
                'endpoint' => null,
                'database' => null,
                'tls' => false,
                'namespace' => (string) ($this->config['namespace'] ?? 'lexnova'),
                'key_prefix' => (string) ($this->config['namespace'] ?? 'lexnova') . '.<bereich>:',
                'default_ttl' => (int) ($this->config['default_ttl'] ?? 3600),
                'apcu' => $diagnostics,
            ];
        } catch (\Throwable) {
            return $this->filesystemStatus('apcu', 'apcu_unavailable');
        }
    }

    /** @return array<string, mixed> */
    private function inspectBlackHole(): array
    {
        if (!class_exists(self::LAMINAS_BLACKHOLE_ADAPTER)) {
            return $this->filesystemStatus('blackhole', 'adapter_unavailable');
        }

        return [
            'configured' => 'blackhole',
            'effective' => 'blackhole',
            'product' => 'Cache deaktiviert',
            'version' => null,
            'client' => self::LAMINAS_BLACKHOLE_ADAPTER,
            'connected' => true,
            'fallback' => false,
            'preferred' => false,
            'detection' => 'diagnostic_mode',
            'path' => null,
            'writable' => null,
            'endpoint' => null,
            'database' => null,
            'tls' => false,
            'namespace' => (string) ($this->config['namespace'] ?? 'lexnova'),
            'key_prefix' => (string) ($this->config['namespace'] ?? 'lexnova') . '.<bereich>:',
            'default_ttl' => (int) ($this->config['default_ttl'] ?? 3600),
            'apcu' => null,
        ];
    }

    private function apcuCache(string $name, int $defaultTtl): CacheInterface
    {
        if (!self::apcuClientAvailable()) {
            throw new \RuntimeException('APCu requires APCu 5.1.10+, an enabled web SAPI and the Laminas APCu adapter.');
        }

        $adapter = self::instantiateOptional(self::LAMINAS_APCU_ADAPTER, [[
            'namespace' => $this->namespace($name),
            'namespace_separator' => ':',
            'ttl' => max(0, $defaultTtl),
        ]]);
        if (!$adapter instanceof StorageInterface) {
            throw new \RuntimeException('The optional Laminas APCu adapter returned an incompatible object.');
        }

        return new SimpleCacheDecorator($adapter);
    }

    private function blackHoleCache(): CacheInterface
    {
        $adapter = self::instantiateOptional(self::LAMINAS_BLACKHOLE_ADAPTER);
        if (!$adapter instanceof StorageInterface) {
            throw new \RuntimeException('The optional Laminas BlackHole adapter returned an incompatible object.');
        }

        return new SimpleCacheDecorator($adapter);
    }

    public static function apcuClientAvailable(): bool
    {
        $version = extension_loaded('apcu') ? phpversion('apcu') : false;
        $enabledFunction = 'apcu_enabled';

        return is_string($version)
            && version_compare($version, '5.1.10', '>=')
            && function_exists($enabledFunction)
            && $enabledFunction()
            && class_exists(self::LAMINAS_APCU_ADAPTER);
    }

    /** @return array<string, int|float|string|bool|null> */
    private function apcuDiagnostics(): array
    {
        $enabledFunction = 'apcu_enabled';
        $enabled = function_exists($enabledFunction) && $enabledFunction();
        $cacheInfo = $this->callApcuInfoFunction('apcu_cache_info');
        $memoryInfo = $this->callApcuInfoFunction('apcu_sma_info');
        $segments = max(0, (int) ($memoryInfo['num_seg'] ?? 0));
        $segmentSize = max(0, (int) ($memoryInfo['seg_size'] ?? 0));
        $total = $segments * $segmentSize;
        $available = max(0, (int) ($memoryInfo['avail_mem'] ?? 0));
        $hits = max(0, (int) ($cacheInfo['num_hits'] ?? 0));
        $misses = max(0, (int) ($cacheInfo['num_misses'] ?? 0));
        $requests = $hits + $misses;
        $lexNovaEntries = null;
        $lexNovaSize = null;
        $lexNovaHits = null;
        $iteratorClass = 'APCUIterator';

        if ($enabled && class_exists($iteratorClass)) {
            try {
                $pattern = '/^' . preg_quote($this->namespace(), '/') . '\\./D';
                $iterator = new $iteratorClass($pattern);
                $lexNovaEntries = $this->optionalIntegerMethod($iterator, 'getTotalCount');
                $lexNovaSize = $this->optionalIntegerMethod($iterator, 'getTotalSize');
                $lexNovaHits = $this->optionalIntegerMethod($iterator, 'getTotalHits');
            } catch (\Throwable) {
                // Some providers restrict APCu iteration; aggregate diagnostics remain useful.
            }
        }

        return [
            'extension_loaded' => extension_loaded('apcu'),
            'enabled' => $enabled,
            'version' => extension_loaded('apcu') ? phpversion('apcu') ?: null : null,
            'adapter_installed' => class_exists(self::LAMINAS_APCU_ADAPTER),
            'sapi' => PHP_SAPI,
            'cli_enabled' => (bool) ini_get('apc.enable_cli'),
            'shared_memory_ini' => (string) ini_get('apc.shm_size'),
            'entries_hint' => (string) ini_get('apc.entries_hint'),
            'serializer' => (string) ini_get('apc.serializer'),
            'total_memory' => $total > 0 ? $total : null,
            'available_memory' => $total > 0 ? $available : null,
            'used_memory' => $total > 0 ? max(0, $total - $available) : null,
            'entries' => isset($cacheInfo['num_entries']) ? max(0, (int) $cacheInfo['num_entries']) : null,
            'hits' => isset($cacheInfo['num_hits']) ? $hits : null,
            'misses' => isset($cacheInfo['num_misses']) ? $misses : null,
            'hit_rate' => $requests > 0 ? round(($hits / $requests) * 100, 2) : null,
            'expunges' => isset($cacheInfo['expunges']) ? max(0, (int) $cacheInfo['expunges']) : null,
            'lexnova_entries' => $lexNovaEntries,
            'lexnova_size' => $lexNovaSize,
            'lexnova_hits' => $lexNovaHits,
        ];
    }

    /** @return array<string, mixed> */
    private function callApcuInfoFunction(string $function): array
    {
        if (!function_exists($function)) {
            return [];
        }

        try {
            $result = @$function(true);

            return is_array($result) ? $result : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function optionalIntegerMethod(object $target, string $method): ?int
    {
        if (!method_exists($target, $method)) {
            return null;
        }

        $value = $target->{$method}();

        return is_int($value) ? $value : null;
    }

    private function valkeyCache(string $name, int $defaultTtl): CacheInterface
    {
        if (!self::valkeyClientAvailable()) {
            throw new \RuntimeException('Valkey requires PhpRedis 6+ and the Laminas Redis adapter.');
        }

        $host = (string) ($this->config['host'] ?? '127.0.0.1');
        if ((bool) ($this->config['tls'] ?? false)) {
            $host = 'tls://' . $host;
        }
        $username = (string) ($this->config['username'] ?? '');
        $password = (string) ($this->config['password'] ?? '');
        $port = min(65535, max(1, (int) ($this->config['port'] ?? 6379)));
        $database = max(0, (int) ($this->config['database'] ?? 0));
        $options = self::instantiateOptional(self::LAMINAS_REDIS_OPTIONS);
        self::callOptional($options, 'setServer', [['host' => $host, 'port' => $port, 'timeout' => 2]]);
        self::callOptional($options, 'setDatabase', [$database]);
        self::callOptional($options, 'setNamespace', [$this->namespace($name)]);
        self::callOptional($options, 'setTtl', [max(0, $defaultTtl)]);
        self::callOptional($options, 'setLibOptions', [[\Redis::OPT_SERIALIZER => \Redis::SERIALIZER_PHP]]);
        if ($username !== '') {
            self::callOptional($options, 'setUser', [$username]);
        }
        if ($password !== '') {
            self::callOptional($options, 'setPassword', [$password]);
        }

        $adapter = self::instantiateOptional(self::LAMINAS_REDIS_ADAPTER, [$options]);
        $resourceManager = self::instantiateOptional(self::LAMINAS_REDIS_RESOURCE_MANAGER, [$options]);
        self::callOptional($adapter, 'setResourceManager', [$resourceManager]);
        $connection = self::callOptional($resourceManager, 'getResource');
        if (!$adapter instanceof StorageInterface || !$connection instanceof \Redis) {
            throw new \RuntimeException('The optional Laminas Redis adapter returned incompatible objects.');
        }
        $this->redisConnection ??= $connection;

        return new SimpleCacheDecorator($adapter);
    }

    public static function valkeyClientAvailable(): bool
    {
        $version = extension_loaded('redis') ? phpversion('redis') : false;

        return is_string($version)
            && version_compare($version, '6.0.0', '>=')
            && class_exists(self::LAMINAS_REDIS_ADAPTER)
            && class_exists(self::LAMINAS_REDIS_OPTIONS)
            && class_exists(self::LAMINAS_REDIS_RESOURCE_MANAGER);
    }

    /** @param list<mixed> $arguments */
    private static function instantiateOptional(string $className, array $arguments = []): object
    {
        if (!class_exists($className)) {
            throw new \RuntimeException('Optional cache class is not installed: ' . $className);
        }

        return new $className(...$arguments);
    }

    /** @param list<mixed> $arguments */
    private static function callOptional(object $target, string $method, array $arguments = []): mixed
    {
        if (!method_exists($target, $method)) {
            throw new \RuntimeException('Optional cache method is unavailable: ' . $method);
        }

        return $target->{$method}(...$arguments);
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
