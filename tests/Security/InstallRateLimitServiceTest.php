<?php

declare(strict_types=1);

use LexNova\Service\InstallRateLimitService;
use Psr\Clock\ClockInterface;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

final class MutableInstallTestClock implements ClockInterface
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

$directory = sys_get_temp_dir() . '/lexnova-install-limit-' . bin2hex(random_bytes(8));
$clock = new MutableInstallTestClock(new DateTimeImmutable('2026-08-13 12:00:00'));
$limiter = new InstallRateLimitService($directory, $clock, 2, 30);

$limiter->recordFailure('192.0.2.10');
if ($limiter->isBlocked('192.0.2.10')) {
    throw new RuntimeException('Installer limiter blocked too early.');
}
$limiter->recordFailure('192.0.2.10');
if (!$limiter->isBlocked('192.0.2.10') || $limiter->secondsRemaining('192.0.2.10') !== 30) {
    throw new RuntimeException('Installer limiter did not block at its threshold.');
}

$clock->advance('+31 seconds');
$limiter->recordFailure('192.0.2.10');
if ($limiter->isBlocked('192.0.2.10')) {
    throw new RuntimeException('Installer limiter did not reset after expiry.');
}

$limiter->recordSuccess('192.0.2.10');
if (glob($directory . '/*.json') !== []) {
    throw new RuntimeException('Installer limiter state was not removed after success.');
}

echo "Installer rate limiter security test: OK\n";
