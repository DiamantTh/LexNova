<?php

declare(strict_types=1);

namespace LexNova\Factory;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Psr\Container\ContainerInterface;

final readonly class DoctrineConnectionFactory
{
    public function __invoke(ContainerInterface $container): Connection
    {
        $config = $container->get('config');
        $params = self::connectionParams((array) ($config['db'] ?? []));

        $connection = DriverManager::getConnection($params);
        if (($params['driver'] ?? null) === 'pdo_sqlite') {
            $connection->executeStatement('PRAGMA foreign_keys = ON');
        }

        return $connection;
    }

    /**
     * @param  array<string, mixed> $db
     * @return array<string, mixed>
     */
    public static function connectionParams(array $db): array
    {
        $driver = (string) ($db['driver'] ?? '');

        if ($driver === 'sqlite') {
            $path = (string) ($db['path'] ?? '');
            if ($path === '') {
                throw new \RuntimeException('SQLite database path is not configured.');
            }

            return [
                'driver' => 'pdo_sqlite',
                'path' => $path,
            ];
        }

        if (!in_array($driver, ['mysql', 'pgsql'], true)) {
            throw new \RuntimeException('Database driver must be sqlite, mysql or pgsql.');
        }

        $host = trim((string) ($db['host'] ?? ''));
        $name = trim((string) ($db['name'] ?? ''));
        if ($host === '' || $name === '') {
            throw new \RuntimeException('Database host and name must be configured.');
        }

        $port = (int) ($db['port'] ?? ($driver === 'mysql' ? 3306 : 5432));
        if ($port < 1 || $port > 65535) {
            throw new \RuntimeException('Database port must be between 1 and 65535.');
        }

        $params = [
            'driver' => $driver === 'mysql' ? 'pdo_mysql' : 'pdo_pgsql',
            'host' => $host,
            'port' => $port,
            'dbname' => $name,
            'user' => (string) ($db['user'] ?? ''),
            'password' => (string) ($db['password'] ?? ''),
        ];
        if ($driver === 'mysql') {
            $params['charset'] = (string) ($db['charset'] ?? 'utf8mb4');
        }

        return $params;
    }
}
