<?php

declare(strict_types=1);

namespace LexNova\Service;

final readonly class InstallService
{
    private string $lockPath;
    private string $configPath;
    private string $passwordPath;

    public function __construct(array $config)
    {
        $install            = $config['install'] ?? [];
        $this->lockPath     = (string) ($install['lock'] ?? '');
        $this->configPath   = (string) ($install['config_file'] ?? '');
        $this->passwordPath = (string) ($install['password_file'] ?? '');
    }

    public function isInstalled(): bool
    {
        return $this->isLocked() && is_file($this->configPath);
    }

    public function isLocked(): bool
    {
        return $this->lockPath !== '' && is_file($this->lockPath);
    }

    public function lock(): void
    {
        file_put_contents($this->lockPath, date('Y-m-d H:i:s') . "\n", LOCK_EX);
    }

    public function readPasswordHash(): ?string
    {
        if (!is_file($this->passwordPath)) {
            return null;
        }
        $value = trim((string) file_get_contents($this->passwordPath));
        return $value !== '' ? $value : null;
    }

    /**
     * Generates a random install password, hashes it with Argon2id, writes the hash
     * to the password file, and returns the plain-text password for one-time display.
     */
    public function initializePassword(array $securityConfig): ?string
    {
        $dir = dirname($this->passwordPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }

        $plain = bin2hex(random_bytes(8));
        $hash  = password_hash(
            $plain,
            $securityConfig['algo'] ?? PASSWORD_ARGON2ID,
            $securityConfig['options'] ?? []
        );

        if ($hash === false) {
            return null;
        }

        if (file_put_contents($this->passwordPath, $hash . "\n", LOCK_EX) === false) {
            return null;
        }

        return $plain;
    }

    public function verifyPassword(string $input, string $stored): bool
    {
        if ($input === '') {
            return false;
        }
        if (str_starts_with($stored, '$argon2')) {
            return password_verify($input, $stored);
        }
        return hash_equals($stored, $input);
    }

    public function writeConfig(string $content): bool
    {
        $dir = dirname($this->configPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        return file_put_contents($this->configPath, $content, LOCK_EX) !== false;
    }

    public function configExists(): bool
    {
        return is_file($this->configPath);
    }
}
