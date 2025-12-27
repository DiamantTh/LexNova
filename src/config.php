<?php

declare(strict_types=1);

function app_config(): array
{
    static $config = null;

    if ($config === null) {
        $configPath = __DIR__ . '/../config/config.php';
        $examplePath = __DIR__ . '/../config/config.example.php';

        if (is_file($configPath)) {
            $config = require $configPath;
        } elseif (is_file($examplePath)) {
            $config = require $examplePath;
        } else {
            throw new RuntimeException('Missing configuration files.');
        }
    }

    return $config;
}

function app_root(): string
{
    return dirname(__DIR__);
}
