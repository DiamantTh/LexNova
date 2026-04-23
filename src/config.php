<?php

declare(strict_types=1);

function app_config(): array
{
    static $config = null;

    if ($config === null) {
        $root        = dirname(__DIR__);
        $configToml  = $root . '/config/config.toml';
        $exampleToml = $root . '/config/config.example.toml';
        $configPhp   = $root . '/config/config.php';
        $examplePhp  = $root . '/config/config.example.php';

        if (is_file($configToml)) {
            $config = toml_decode((string) file_get_contents($configToml), asArray: true);
            $config['security'] = _load_security_toml($root);
        } elseif (is_file($exampleToml)) {
            $config = toml_decode((string) file_get_contents($exampleToml), asArray: true);
            $config['security'] = _load_security_toml($root);
        } elseif (is_file($configPhp)) {
            $config = require $configPhp;
        } elseif (is_file($examplePhp)) {
            $config = require $examplePhp;
        } else {
            throw new RuntimeException('No configuration file found (config/config.toml).');
        }
    }

    return $config;
}

function _load_security_toml(string $root): array
{
    $path = $root . '/config/security.toml';
    if (!is_file($path)) {
        return [];
    }
    return toml_decode((string) file_get_contents($path), asArray: true);
}

function app_root(): string
{
    return dirname(__DIR__);
}
