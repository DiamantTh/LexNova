<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function install_lock_path(): string
{
    $config = app_config();
    return $config['install']['lock'] ?? (app_root() . '/install/install.lock');
}

function config_file_path(): string
{
    $config = app_config();
    return $config['install']['config_file'] ?? (app_root() . '/config/config.php');
}

function install_password_path(): string
{
    $config = app_config();
    return $config['install']['password_file'] ?? (app_root() . '/install/install.pw');
}

function is_installed(): bool
{
    return is_install_locked() && is_file(config_file_path());
}

function is_install_locked(): bool
{
    return is_file(install_lock_path());
}

function read_install_password_hash(): ?string
{
    $path = install_password_path();

    if (!is_file($path)) {
        return null;
    }

    $value = trim((string) file_get_contents($path));
    return $value !== '' ? $value : null;
}

function verify_install_password(string $input, string $stored): bool
{
    if ($input === '') {
        return false;
    }

    if (str_starts_with($stored, '$argon2')) {
        return password_verify($input, $stored);
    }

    return hash_equals($stored, $input);
}
