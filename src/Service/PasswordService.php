<?php

declare(strict_types=1);

namespace LexNova\Service;

use LexNova\Service\Password\BreachedPasswordCheckerInterface;
use LexNova\Service\Password\NullBreachedPasswordChecker;
use ZxcvbnPhp\Zxcvbn;

final readonly class PasswordService
{
    private int $minLength;
    private int $maxLength;
    private bool $asciiOnly;
    /** @var int zxcvbn score threshold (0 = disabled, 1–4 = required minimum) */
    private int $minScore;
    /** @var int HIBP minimum breach count to reject (>=1; only consulted when checker is non-null) */
    private int $hibpMinCount;
    private string $algo;
    /** @var array<string, mixed> */
    private array $options;
    private BreachedPasswordCheckerInterface $breachChecker;

    /** @param array<string, mixed> $config */
    public function __construct(
        array $config,
        ?BreachedPasswordCheckerInterface $breachChecker = null,
    ) {
        $policy = $config['security']['password_policy'] ?? [];
        $this->minLength = max(8, (int) ($policy['min_length'] ?? 16));
        $this->maxLength = min(256, max($this->minLength, (int) ($policy['max_length'] ?? 256)));
        $this->asciiOnly = (bool) ($policy['ascii_only'] ?? true);
        $this->minScore = min(4, max(0, (int) ($policy['min_score'] ?? 2)));
        $this->hibpMinCount = max(1, (int) ($policy['hibp']['min_count'] ?? 1));

        $pw = $config['security']['password'] ?? [];
        $this->algo = $pw['algo'] ?? PASSWORD_ARGON2ID;
        $this->options = $pw['options'] ?? [];

        $this->breachChecker = $breachChecker ?? new NullBreachedPasswordChecker();
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

        if ($this->minScore > 0) {
            $result = (new Zxcvbn())->passwordStrength($password);
            if ($result['score'] < $this->minScore) {
                return "Password is too weak (strength {$result['score']}/4). "
                     . 'Use a longer password or avoid common words and predictable patterns.';
            }
        }

        $seen = $this->breachChecker->timesSeen($password);
        if ($seen >= $this->hibpMinCount) {
            return 'This password appears in known data breaches '
                 . "({$seen} occurrences via HaveIBeenPwned). "
                 . 'Please choose a different password.';
        }

        return null;
    }

    public function hash(string $password): string
    {
        $hash = password_hash($password, $this->algo, $this->options);
        if ($hash === false) { // @phpstan-ignore identical.alwaysFalse
            throw new \RuntimeException('Password hashing failed.');
        }

        return $hash;
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}
