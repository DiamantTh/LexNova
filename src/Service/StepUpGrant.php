<?php

declare(strict_types=1);

namespace LexNova\Service;

/** Server-verified, one-shot evidence for a bound step-up action. */
final readonly class StepUpGrant
{
    public function __construct(
        public int $userId,
        public string $action,
        public string $target,
        public string $method,
        public int $authenticatorId,
    ) {
    }
}
