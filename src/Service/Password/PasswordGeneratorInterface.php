<?php

declare(strict_types=1);

namespace LexNova\Service\Password;

interface PasswordGeneratorInterface
{
    /**
     * Generate a new password or passphrase using a CSPRNG.
     */
    public function generate(): string;

    /**
     * Estimated entropy of generated passwords in bits.
     * Useful for displaying password strength information.
     */
    public function entropyBits(): float;
}
