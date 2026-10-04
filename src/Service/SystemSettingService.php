<?php

declare(strict_types=1);

namespace LexNova\Service;

use Doctrine\DBAL\Connection;
use Psr\SimpleCache\CacheInterface;

final readonly class SystemSettingService
{
    private const CACHE_PREFIX = 'system_setting_';

    public function __construct(
        private Connection $db,
        private CacheInterface $cache,
        private int $cacheTtl = 60,
    ) {
    }

    /**
     * The database value has priority. If no row (or no settings table before
     * installation) exists, the configuration default is returned.
     *
     * @return array{value: bool, source: 'database'|'config'}
     */
    public function bool(string $key, bool $configDefault): array
    {
        $cacheKey = $this->cacheKey($key);
        try {
            $cached = $this->cache->get($cacheKey);
        } catch (\Throwable) {
            $cached = null;
        }
        if (is_array($cached) && isset($cached['value'], $cached['source'])) {
            return [
                'value' => (bool) $cached['value'],
                'source' => $cached['source'] === 'database' ? 'database' : 'config',
            ];
        }

        $result = ['value' => $configDefault, 'source' => 'config'];

        try {
            $value = $this->db->fetchOne(
                'SELECT setting_value FROM system_settings WHERE setting_key = ?',
                [$key],
            );
            if (is_string($value)) {
                $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                if ($parsed !== null) {
                    $result = ['value' => $parsed, 'source' => 'database'];
                }
            }
        } catch (\Throwable) {
            // Before installation or migration there is no settings table yet.
        }

        try {
            $this->cache->set($cacheKey, $result, $this->cacheTtl);
        } catch (\Throwable) {
            // A cache failure must not make authentication unavailable.
        }

        return $result;
    }

    public function setBool(string $key, bool $value): void
    {
        $stored = $value ? 'true' : 'false';
        $now = gmdate('Y-m-d H:i:s');

        $this->db->transactional(function (Connection $db) use ($key, $stored, $now): void {
            $exists = $db->fetchOne(
                'SELECT setting_key FROM system_settings WHERE setting_key = ?',
                [$key],
            );

            if (is_string($exists)) {
                $db->update('system_settings', [
                    'setting_value' => $stored,
                    'updated_at' => $now,
                ], ['setting_key' => $key]);

                return;
            }

            $db->insert('system_settings', [
                'setting_key' => $key,
                'setting_value' => $stored,
                'updated_at' => $now,
            ]);
        });

        $this->invalidate($key);
    }

    /** @return array{value: int, source: 'database'|'config'} */
    public function int(string $key, int $configDefault, int $hardCap = 100, int $minimum = 0): array
    {
        $cacheKey = $this->cacheKey($key);
        try {
            $cached = $this->cache->get($cacheKey);
        } catch (\Throwable) {
            $cached = null;
        }
        if (is_array($cached) && isset($cached['value'], $cached['source']) && is_int($cached['value'])) {
            return [
                'value' => max($minimum, min($hardCap, $cached['value'])),
                'source' => $cached['source'] === 'database' ? 'database' : 'config',
            ];
        }

        $result = ['value' => max($minimum, min($hardCap, $configDefault)), 'source' => 'config'];
        try {
            $value = $this->db->fetchOne('SELECT setting_value FROM system_settings WHERE setting_key = ?', [$key]);
            $parsed = is_string($value) && preg_match('/^-?\d+$/D', $value) === 1 ? (int) $value : null;
            if ($parsed !== null) {
                $result = ['value' => max($minimum, min($hardCap, $parsed)), 'source' => 'database'];
            }
        } catch (\Throwable) {
            // Before installation there is no settings table yet.
        }

        try {
            $this->cache->set($cacheKey, $result, $this->cacheTtl);
        } catch (\Throwable) {
            // Cache is optional; the database is authoritative.
        }

        return $result;
    }

    public function setInt(string $key, int $value, int $hardCap = 100, int $minimum = 0): void
    {
        if ($value < $minimum || $value > $hardCap) {
            throw new \InvalidArgumentException(sprintf('Setting %s must be between %d and %d.', $key, $minimum, $hardCap));
        }

        $stored = (string) $value;
        $now = gmdate('Y-m-d H:i:s');
        $this->db->transactional(function (Connection $db) use ($key, $stored, $now): void {
            $exists = $db->fetchOne('SELECT setting_key FROM system_settings WHERE setting_key = ?', [$key]);
            if (is_string($exists)) {
                $db->update('system_settings', ['setting_value' => $stored, 'updated_at' => $now], ['setting_key' => $key]);

                return;
            }
            $db->insert('system_settings', ['setting_key' => $key, 'setting_value' => $stored, 'updated_at' => $now]);
        });
        $this->invalidate($key);
    }

    public function remove(string $key): void
    {
        $this->db->delete('system_settings', ['setting_key' => $key]);
        $this->invalidate($key);
    }

    private function cacheKey(string $key): string
    {
        return self::CACHE_PREFIX . hash('sha256', $key);
    }

    private function invalidate(string $key): void
    {
        try {
            $this->cache->delete($this->cacheKey($key));
        } catch (\Throwable) {
            // The database value is authoritative; cache expiry repairs stale data.
        }
    }
}
