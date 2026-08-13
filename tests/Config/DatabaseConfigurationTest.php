<?php

declare(strict_types=1);

use LexNova\Factory\DoctrineConnectionFactory;
use LexNova\Handler\Install\Step\ConfigureStep;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$mysql = DoctrineConnectionFactory::connectionParams([
    'driver' => 'mysql',
    'host' => 'db.example.test',
    'port' => 3307,
    'name' => 'lexnova',
    'user' => 'lexnova_user',
    'password' => 'secret',
    'charset' => 'utf8mb4',
]);
if ($mysql !== [
    'driver' => 'pdo_mysql',
    'host' => 'db.example.test',
    'port' => 3307,
    'dbname' => 'lexnova',
    'user' => 'lexnova_user',
    'password' => 'secret',
    'charset' => 'utf8mb4',
]) {
    throw new RuntimeException('Classic MySQL configuration was mapped incorrectly.');
}

$pgsql = DoctrineConnectionFactory::connectionParams([
    'driver' => 'pgsql',
    'host' => 'localhost',
    'name' => 'lexnova',
    'user' => 'postgres',
    'password' => 'secret',
]);
if ($pgsql['driver'] !== 'pdo_pgsql' || $pgsql['port'] !== 5432 || isset($pgsql['charset'])) {
    throw new RuntimeException('Classic PostgreSQL configuration was mapped incorrectly.');
}

$sqlite = DoctrineConnectionFactory::connectionParams([
    'driver' => 'sqlite',
    'path' => '/srv/lexnova/data/lexnova.sqlite',
]);
if ($sqlite !== ['driver' => 'pdo_sqlite', 'path' => '/srv/lexnova/data/lexnova.sqlite']) {
    throw new RuntimeException('Classic SQLite configuration was mapped incorrectly.');
}

$buildConfig = new ReflectionMethod(ConfigureStep::class, 'buildConfigFile');
$generatedToml = $buildConfig->invoke(
    new ConfigureStep(),
    [
        'dbType' => 'mysql',
        'dbHost' => 'db.example.test',
        'dbPort' => '3307',
        'dbName' => 'lexnova',
        'dbPath' => '',
        'dbUser' => 'lexnova_user',
        'dbPassword' => 'secret',
    ],
    'https://legal.example.test',
    'de',
    str_repeat('a', 64),
    '/srv/lexnova',
);
$generated = toml_decode((string) $generatedToml, asArray: true);
if (!is_array($generated)
    || ($generated['db']['driver'] ?? null) !== 'mysql'
    || ($generated['db']['host'] ?? null) !== 'db.example.test'
    || ($generated['db']['port'] ?? null) !== 3307
    || array_key_exists('dsn', (array) ($generated['db'] ?? []))
) {
    throw new RuntimeException('Browser installer did not generate classic database fields.');
}

try {
    DoctrineConnectionFactory::connectionParams(['driver' => 'mysql', 'host' => '', 'name' => '']);
    throw new RuntimeException('Incomplete database configuration was accepted.');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'Incomplete database configuration was accepted.') {
        throw $exception;
    }
}

echo "Classic database configuration test: OK\n";
