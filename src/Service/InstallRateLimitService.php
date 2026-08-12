<?php

declare(strict_types=1);

namespace LexNova\Service;

use Psr\Clock\ClockInterface;

/** File-backed limiter used before an application database exists. */
final readonly class InstallRateLimitService
{
    public function __construct(
        private string $directory,
        private ClockInterface $clock,
        private int $maxAttempts = 5,
        private int $blockSeconds = 300,
    ) {
    }

    public function isBlocked(string $ip): bool
    {
        $state = $this->read($ip);

        return isset($state['blocked_until']) && (int) $state['blocked_until'] > $this->clock->now()->getTimestamp();
    }

    public function secondsRemaining(string $ip): int
    {
        $state = $this->read($ip);

        return max(0, (int) ($state['blocked_until'] ?? 0) - $this->clock->now()->getTimestamp());
    }

    public function recordFailure(string $ip): void
    {
        $this->mutate($ip, function (array $state): array {
            $now = $this->clock->now()->getTimestamp();
            $blockedUntil = (int) ($state['blocked_until'] ?? 0);
            $lastAt = (int) ($state['last_at'] ?? 0);
            $expired = ($blockedUntil > 0 && $blockedUntil <= $now)
                || ($lastAt > 0 && $lastAt <= $now - $this->blockSeconds);
            $attempts = $expired ? 1 : (int) ($state['attempts'] ?? 0) + 1;

            return [
                'attempts' => $attempts,
                'blocked_until' => $attempts >= $this->maxAttempts ? $now + $this->blockSeconds : null,
                'last_at' => $now,
            ];
        });
    }

    public function recordSuccess(string $ip): void
    {
        $path = $this->path($ip);
        if (is_file($path)) {
            unlink($path);
        }
    }

    /** @return array{attempts?: int, blocked_until?: int|null, last_at?: int} */
    private function read(string $ip): array
    {
        $path = $this->path($ip);
        if (!is_file($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                return [];
            }
            $contents = stream_get_contents($handle);
            $decoded = json_decode($contents !== false ? $contents : '', true);

            return is_array($decoded) ? $decoded : [];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param callable(array{attempts?: int, blocked_until?: int|null, last_at?: int}): array{attempts: int, blocked_until: int|null, last_at: int} $callback
     */
    private function mutate(string $ip, callable $callback): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Cannot create installer rate-limit directory.');
        }

        $path = $this->path($ip);
        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open installer rate-limit state.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock installer rate-limit state.');
            }
            $contents = stream_get_contents($handle);
            $decoded = json_decode($contents !== false ? $contents : '', true);
            $state = $callback(is_array($decoded) ? $decoded : []);
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR));
            fflush($handle);
            chmod($path, 0600);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function path(string $ip): string
    {
        return rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . hash('sha256', $ip) . '.json';
    }
}
