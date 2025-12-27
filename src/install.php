<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function install_lock_path(): string
{
    return app_root() . '/install.lock';
}

function database_config_path(): string
{
    return app_root() . '/config/database.php';
}

function install_password_path(): string
{
    return app_root() . '/config/install.pw';
}

function is_installed(): bool
{
    return is_install_locked() && is_file(database_config_path());
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
