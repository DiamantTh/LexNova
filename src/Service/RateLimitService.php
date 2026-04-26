<?php

declare(strict_types=1);

namespace LexNova\Service;

use Doctrine\DBAL\Connection;

/**
 * Simple DB-backed rate limiter for the login and TOTP-verify endpoints.
 *
 * Strategy: sliding window per (IP, endpoint).
 * After $maxAttempts failures the IP is blocked for $blockSeconds seconds.
 * A successful login/verification clears the counter for that IP.
 */
final readonly class RateLimitService
{
    public function __construct(
        private readonly Connection $db,
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

        return new \DateTimeImmutable($row['blocked_until']) > new \DateTimeImmutable('now');
    }

    /**
     * Records a failed attempt and potentially sets a block.
     */
    public function recordFailure(string $ip, string $endpoint): void
    {
        $now = date('Y-m-d H:i:s');
        $row = $this->fetch($ip, $endpoint);

        if ($row === null) {
            $this->db->insert('login_attempts', [
                'ip' => $ip,
                'endpoint' => $endpoint,
                'attempts' => 1,
                'blocked_until' => null,
                'last_at' => $now,
            ]);

            return;
        }

        $attempts = (int) $row['attempts'] + 1;
        $blockedUntil = null;

        if ($attempts >= $this->maxAttempts) {
            $until = new \DateTimeImmutable("now +{$this->blockSeconds} seconds");
            $blockedUntil = $until->format('Y-m-d H:i:s');
        }

        $this->db->update('login_attempts', [
            'attempts' => $attempts,
            'blocked_until' => $blockedUntil,
            'last_at' => $now,
        ], ['ip' => $ip, 'endpoint' => $endpoint]);
    }

    /**
     * Clears the attempt counter after a successful login/verify.
     */
    public function recordSuccess(string $ip, string $endpoint): void
    {
        $this->db->delete('login_attempts', ['ip' => $ip, 'endpoint' => $endpoint]);
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

        $until = new \DateTimeImmutable($row['blocked_until']);
        $diff = $until->getTimestamp() - time();

        return max(0, $diff);
    }

    /** @return array<string,mixed>|null */
    private function fetch(string $ip, string $endpoint): ?array
    {
        $row = $this->db->createQueryBuilder()
            ->select('ip', 'endpoint', 'attempts', 'blocked_until', 'last_at')
            ->from('login_attempts')
            ->where('ip = :ip AND endpoint = :endpoint')
            ->setParameter('ip', $ip)
            ->setParameter('endpoint', $endpoint)
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }
}
