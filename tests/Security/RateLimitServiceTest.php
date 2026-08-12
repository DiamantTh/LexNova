<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use LexNova\Service\RateLimitService;
use Psr\Clock\ClockInterface;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

final class MutableTestClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $current)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->current;
    }

    public function advance(string $interval): void
    {
        $this->current = $this->current->modify($interval);
    }
}

$db = DriverManager::getConnection(['url' => 'sqlite:///:memory:']);
$db->executeStatement(<<<'SQL'
CREATE TABLE login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip VARCHAR(45) NOT NULL,
    endpoint VARCHAR(50) NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 1,
    blocked_until DATETIME DEFAULT NULL,
    last_at DATETIME NOT NULL,
    UNIQUE (ip, endpoint)
)
SQL);

$clock = new MutableTestClock(new DateTimeImmutable('2026-08-13 12:00:00'));
$limiter = new RateLimitService($db, $clock, maxAttempts: 3, blockSeconds: 60);

$limiter->recordFailure('192.0.2.1', 'login');
$limiter->recordFailure('192.0.2.1', 'login');
if ($limiter->isBlocked('192.0.2.1', 'login')) {
    throw new RuntimeException('Limiter blocked before the configured threshold.');
}

$limiter->recordFailure('192.0.2.1', 'login');
if (!$limiter->isBlocked('192.0.2.1', 'login') || $limiter->secondsRemaining('192.0.2.1', 'login') !== 60) {
    throw new RuntimeException('Limiter did not block at the configured threshold.');
}

$clock->advance('+61 seconds');
if ($limiter->isBlocked('192.0.2.1', 'login')) {
    throw new RuntimeException('Limiter remained blocked after expiry.');
}

$limiter->recordFailure('192.0.2.1', 'login');
$row = $db->fetchAssociative('SELECT attempts, blocked_until FROM login_attempts WHERE ip = ?', ['192.0.2.1']);
if ((int) ($row['attempts'] ?? 0) !== 1 || $row['blocked_until'] !== null) {
    throw new RuntimeException('Expired failure window was not reset.');
}

$limiter->recordSuccess('192.0.2.1', 'login');
if ($db->fetchOne('SELECT COUNT(*) FROM login_attempts') !== 0) {
    throw new RuntimeException('Successful authentication did not clear the limiter.');
}

echo "Rate limiter security test: OK\n";
