<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$container = \LexNova\Application\ContainerFactory::create();

/** @var \Mezzio\Application $app */
$app     = $container->get(\Mezzio\Application::class);
$factory = $container->get(\Mezzio\MiddlewareFactory::class);

\LexNova\Application\Pipeline::configure($app, $factory, $container);
\LexNova\Application\Routes::configure($app);

$app->run();
