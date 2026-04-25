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



if ($hash === '') {
    http_response_code(400);
    $error = 'Missing required parameter: hash.';
} else {
    $entity = get_entity_by_hash($hash);

    if (!$entity) {
        http_response_code(404);
        $error = 'Entity not found.';
    } else {
        $document = get_latest_document((int) $entity['id'], $mode);
        if (!$document) {
            http_response_code(404);
            $error = 'No document found for this entity.';
        }
    }
}

$title = $mode === 'privacy' ? 'Privacy Policy' : 'Imprint';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h($title); ?> | LexNova</title>
    <style>
        :root {
            color-scheme: light;
        }
        body {
            font-family: "Georgia", "Times New Roman", serif;
            margin: 0;
            background: #f4f1ea;
            color: #1f1f1f;
        }
        .wrap {
            max-width: 900px;
            margin: 0 auto;
            padding: 48px 20px 80px;
        }
        header {
            margin-bottom: 32px;
        }
        h1 {
            font-size: 32px;
            margin: 0 0 8px;
        }
        .meta {
            font-size: 14px;
            color: #4a4a4a;
        }
        .card {
            background: #ffffff;
            padding: 28px;
            border: 1px solid #e0d7c9;
            border-radius: 12px;
            box-shadow: 0 12px 30px rgba(39, 34, 28, 0.06);
        }
        .section-title {
            font-size: 18px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin: 28px 0 12px;
            color: #5c4a34;
        }
        .content {
            line-height: 1.6;
            white-space: pre-wrap;
        }
        .error {
            background: #fff4f2;
            border: 1px solid #f3b7a8;
            color: #7b1c0e;
            padding: 16px;
            border-radius: 10px;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <header>
            <div class="meta">LexNova Legal Documents</div>
            <h1><?php echo h($title); ?></h1>
        </header>
        <div class="card">
            <?php if ($error): ?>
                <div class="error"><?php echo h($error); ?></div>
            <?php else: ?>
                <div class="meta">Entity: <?php echo h((string) $entity['name']); ?> · Hash: <?php echo h((string) $entity['hash']); ?></div>
                <div class="section-title">Contact</div>
                <div class="content"><?php echo render_text((string) $entity['contact_data']); ?></div>
                <div class="section-title">Document</div>
                <div class="content"><?php echo render_text((string) $document['content']); ?></div>
                <div class="meta">Version <?php echo h((string) $document['version']); ?> · Updated <?php echo h((string) $document['updated_at']); ?></div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
