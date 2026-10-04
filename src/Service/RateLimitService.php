<?php

declare(strict_types=1);

namespace LexNova\Service;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Simple DB-backed rate limiter for the login and TOTP-verify endpoints.
 *
 * Strategy: failure window per (IP, endpoint).
 * After $maxAttempts failures the IP is blocked for $blockSeconds seconds.
 * A successful login/verification clears the counter for that IP.
 */
final readonly class RateLimitService
{
    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
        private readonly int $maxAttempts = 5,
        private readonly int $blockSeconds = 300,  // 5 minutes
    ) {
    }

    /**
     * Returns true if the IP is currently blocked for $endpoint.
     */
    public function isBlocked(string $ip, string $endpoint): bool
    {
        $row = $this->fetch($ip, $endpoint);

        if ($row === null) {
            return false;
        }

        if ($row['blocked_until'] === null) {
            return false;
        }

        return $this->dateTimeUtc($row['blocked_until']) > $this->clock->now();
    }

    /**
     * Records a failed attempt and potentially sets a block.
     */
    public function recordFailure(string $ip, string $endpoint): void
    {
        $now = $this->clock->now();
        $nowString = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $row = $this->fetch($ip, $endpoint);

        if ($row === null) {
            $this->db->insert('rate_limit_buckets', [
                'ip' => $ip,
                'endpoint' => $endpoint,
                'attempts' => 1,
                'blocked_until' => null,
                'last_at' => $nowString,
            ]);

            return;
        }

        $lastAttempt = $this->dateTimeUtc((string) $row['last_at']);
        $blockExpired = $row['blocked_until'] !== null
            && $this->dateTimeUtc((string) $row['blocked_until']) <= $now;
        $windowExpired = $lastAttempt <= $now->modify("-{$this->blockSeconds} seconds");
        $attempts = ($blockExpired || $windowExpired) ? 1 : (int) $row['attempts'] + 1;
        $blockedUntil = null;

        if ($attempts >= $this->maxAttempts) {
            $until = $now->modify("+{$this->blockSeconds} seconds");
            $blockedUntil = $until->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }

        $this->db->update('rate_limit_buckets', [
            'attempts' => $attempts,
            'blocked_until' => $blockedUntil,
            'last_at' => $nowString,
        ], ['ip' => $ip, 'endpoint' => $endpoint]);
    }

    /**
     * Clears the attempt counter after a successful login/verify.
     */
    public function recordSuccess(string $ip, string $endpoint): void
    {
        $this->db->delete('rate_limit_buckets', ['ip' => $ip, 'endpoint' => $endpoint]);
    }

    /**
     * Returns seconds remaining in the current block, or 0 if not blocked.
     */
    public function secondsRemaining(string $ip, string $endpoint): int
    {
        $row = $this->fetch($ip, $endpoint);

        if ($row === null || $row['blocked_until'] === null) {
            return 0;
        }

        $until = $this->dateTimeUtc($row['blocked_until']);
        $diff = $until->getTimestamp() - $this->clock->now()->getTimestamp();

        return max(0, $diff);
    }

    /** @return array<string,mixed>|null */
    private function fetch(string $ip, string $endpoint): ?array
    {
        $row = $this->db->createQueryBuilder()
            ->select('ip', 'endpoint', 'attempts', 'blocked_until', 'last_at')
            ->from('rate_limit_buckets')
            ->where('ip = :ip AND endpoint = :endpoint')
            ->setParameter('ip', $ip)
            ->setParameter('endpoint', $endpoint)
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }

    private function dateTimeUtc(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
