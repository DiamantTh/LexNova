<?php

declare(strict_types=1);

namespace LexNova\Service\Password;

/**
 * No-op checker — used when HIBP integration is disabled in config.
 */
final class NullBreachedPasswordChecker implements BreachedPasswordCheckerInterface
{
    public function timesSeen(string $password): int
    {
        return 0;
    }
}
