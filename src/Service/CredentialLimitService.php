<?php

declare(strict_types=1);

namespace LexNova\Service;

/** Per-user credential limits with a non-configurable upper bound. */
final readonly class CredentialLimitService
{
    public function __construct(
        private SystemSettingService $settings,
        private int $webauthnDefault = 10,
        private int $totpDefault = 5,
    ) {
    }

    public function limit(string $kind): int
    {
        return match ($kind) {
            'webauthn' => $this->settings->int('auth.limit.webauthn', $this->webauthnDefault, 100, 1)['value'],
            'totp' => $this->settings->int('auth.limit.totp', $this->totpDefault, 100, 1)['value'],
            default => throw new \InvalidArgumentException('Unknown authentication credential kind.'),
        };
    }

    public function assertCanAdd(string $kind, int $currentCount): void
    {
        $limit = $this->limit($kind);
        if ($currentCount >= $limit) {
            throw new \RuntimeException(sprintf('The %s credential limit has been reached. Remove a credential before adding another.', $kind));
        }
    }
}
