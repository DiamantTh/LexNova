<?php

declare(strict_types=1);

namespace LexNova\Factory;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

final readonly class LoggerFactory
{
    public function __invoke(ContainerInterface $container): LoggerInterface
    {
        $config = $container->get('config');
        $log    = $config['log'] ?? [];

        $path  = (string) ($log['path'] ?? 'php://stderr');
        $level = $log['level'] ?? 'warning';

        $logger = new Logger('lexnova');
        $logger->pushHandler(new StreamHandler($path, $level));

        return $logger;
    }
}
