<?php

declare(strict_types=1);

function app_config(): array
{
    static $config = null;

    if ($config === null) {
        $config = require __DIR__ . '/../config/config.php';
    }

    return $config;
}

function app_root(): string
{
    return dirname(__DIR__);
}
