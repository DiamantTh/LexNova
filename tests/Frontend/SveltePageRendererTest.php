<?php

declare(strict_types=1);

use LexNova\Frontend\SveltePageRenderer;
use LexNova\Service\TranslationService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$manifest = $root . '/httpdocs/assets/app/.vite/manifest.json';
$renderer = new SveltePageRenderer(
    $manifest,
    translations: new TranslationService($root . '/resources/translations', 'de'),
);
$html = $renderer->render(
    'renderer-test',
    ['payload' => '</script><script>alert(1)</script>'],
    'Renderer <Test>',
    'de-DE',
);

if (str_contains($html, '</script><script>alert(1)</script>')) {
    throw new RuntimeException('Bootstrap JSON can terminate its inert script element.');
}
if (!str_contains($html, '\u003C\/script\u003E')) {
    throw new RuntimeException('Bootstrap JSON is not HTML-safe.');
}
if (!str_contains($html, '<html lang="de-DE" data-theme="cerberus">')) {
    throw new RuntimeException('Locale or theme is missing from the application shell.');
}
if (!str_contains($html, 'Renderer &lt;Test&gt;')) {
    throw new RuntimeException('The page title is not escaped.');
}
if (!str_contains($html, '/assets/app/assets/app-')) {
    throw new RuntimeException('The built frontend entry is not linked.');
}
if (!str_contains($html, '"Imprint":"Impressum"')) {
    throw new RuntimeException('The frontend translation catalogue is missing.');
}

echo "Svelte page renderer test: OK\n";
