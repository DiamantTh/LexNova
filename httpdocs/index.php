<?php

declare(strict_types=1);

// Guard: if vendor/ is missing, composer install has not been run yet.
if (!is_file(__DIR__ . '/../vendor/autoload.php')) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
       . '<title>Setup unvollständig – LexNova</title>'
       . '<style>body{font-family:sans-serif;max-width:600px;margin:80px auto;padding:0 24px;}'
       . 'code{background:#f0ece4;padding:2px 6px;border-radius:3px;font-size:.95em;}</style>'
       . '</head><body>'
       . '<h1>Setup unvollständig</h1>'
       . '<p><code>vendor/autoload.php</code> wurde nicht gefunden.</p>'
       . '<p>Bitte zuerst Abhängigkeiten installieren:</p>'
       . '<pre><code>composer install --no-dev</code></pre>'
       . '</body></html>';
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

$container = \LexNova\Application\ContainerFactory::create();

/** @var \Mezzio\Application $app */
$app     = $container->get(\Mezzio\Application::class);
$factory = $container->get(\Mezzio\MiddlewareFactory::class);

\LexNova\Application\Pipeline::configure($app, $factory, $container);
\LexNova\Application\Routes::configure($app);

$app->run();
