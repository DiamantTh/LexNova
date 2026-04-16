<?php

declare(strict_types=1);

namespace LexNova\Service;

final readonly class PasswordService
{
    private int $minLength;
    private int $maxLength;
    private bool $asciiOnly;
    private string $algo;
    private array $options;

    public function __construct(array $config)
    {
        $policy          = $config['security']['password_policy'] ?? [];
        $this->minLength = max(8, (int) ($policy['min_length'] ?? 16));
        $this->maxLength = min(256, max($this->minLength, (int) ($policy['max_length'] ?? 256)));
        $this->asciiOnly = (bool) ($policy['ascii_only'] ?? true);

        $pw            = $config['security']['password'] ?? [];
        $this->algo    = $pw['algo'] ?? PASSWORD_ARGON2ID;
        $this->options = $pw['options'] ?? [];
    }

    public function getMinLength(): int
    {
        return $this->minLength;
    }

    public function getMaxLength(): int
    {
        return $this->maxLength;
    }

    /**
     * Validates a plain-text password against the configured policy.
     * Returns an error message on violation, or null when valid.
     */
    public function validate(string $password): ?string
    {
        $len = strlen($password);

        if ($len < $this->minLength) {
            return "Password must be at least {$this->minLength} characters long.";
        }

        if ($len > $this->maxLength) {
            return "Password must not exceed {$this->maxLength} characters.";
        }

        if ($this->asciiOnly && !preg_match('/^[\x20-\x7E]+$/', $password)) {
            return 'Password may only contain standard printable ASCII characters '
                 . '(letters, digits and keyboard symbols like !, @, #, $, …). '
                 . 'Accented, non-Latin or special Unicode characters are not allowed '
                 . 'to prevent keyboard-layout lockouts.';
        }

        return null;
    }

    public function hash(string $password): string
    {
        $hash = password_hash($password, $this->algo, $this->options);
        if ($hash === false) {
            throw new \RuntimeException('Password hashing failed.');
        }
        return $hash;
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}
