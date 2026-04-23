<?php

declare(strict_types=1);

namespace LexNova\Factory;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Psr\Container\ContainerInterface;
use RuntimeException;

final readonly class DoctrineConnectionFactory
{
    public function __invoke(ContainerInterface $container): Connection
    {
        $config = $container->get('config');
        $db     = $config['db'] ?? [];
        $dsn    = (string) ($db['dsn'] ?? '');

        if ($dsn === '') {
            throw new RuntimeException('Database DSN is not configured.');
        }

        $user     = ($db['user'] ?? '') !== '' ? (string) $db['user'] : null;
        $password = ($db['password'] ?? '') !== '' ? (string) $db['password'] : null;

        $params = self::parseDsn($dsn, $user, $password);

        return DriverManager::getConnection($params);
    }

    /**
     * Maps a PDO-style DSN + optional credentials to Doctrine DBAL connection params.
     *
     * Supported drivers: sqlite, mysql/mariadb, pgsql.
     */
    private static function parseDsn(string $dsn, ?string $user, ?string $password): array
    {
        if (str_starts_with($dsn, 'sqlite:')) {
            $path = ltrim(substr($dsn, 7), '/');
            return [
                'driver' => 'pdo_sqlite',
                'path'   => '/' . $path,
            ];
        }

        if (str_starts_with($dsn, 'mysql:')) {
            return array_merge(
                self::parseMysqlDsn($dsn),
                [
                    'driver'   => 'pdo_mysql',
                    'user'     => $user,
                    'password' => $password,
                    'charset'  => 'utf8mb4',
                ]
            );
        }

        if (str_starts_with($dsn, 'pgsql:')) {
            return array_merge(
                self::parsePgsqlDsn($dsn),
                [
                    'driver'   => 'pdo_pgsql',
                    'user'     => $user,
                    'password' => $password,
                ]
            );
        }

        throw new RuntimeException("Unsupported database driver in DSN: {$dsn}");
    }

    private static function parseMysqlDsn(string $dsn): array
    {
        $parts = [];
        $raw   = substr($dsn, 6); // strip "mysql:"
        foreach (explode(';', $raw) as $segment) {
            [$k, $v] = array_pad(explode('=', $segment, 2), 2, '');
            $parts[trim($k)] = trim($v);
        }
        $out = ['host' => $parts['host'] ?? 'localhost'];
        if (isset($parts['port'])) {
            $out['port'] = (int) $parts['port'];
        }
        if (isset($parts['dbname'])) {
            $out['dbname'] = $parts['dbname'];
        }
        return $out;
    }

    private static function parsePgsqlDsn(string $dsn): array
    {
        $parts = [];
        $raw   = substr($dsn, 6); // strip "pgsql:"
        foreach (explode(';', $raw) as $segment) {
            [$k, $v] = array_pad(explode('=', $segment, 2), 2, '');
            $parts[trim($k)] = trim($v);
        }
        $out = ['host' => $parts['host'] ?? 'localhost'];
        if (isset($parts['port'])) {
            $out['port'] = (int) $parts['port'];
        }
        if (isset($parts['dbname'])) {
            $out['dbname'] = $parts['dbname'];
        }
        return $out;
    }
}
