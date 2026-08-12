<?php

declare(strict_types=1);

use LexNova\Clock\SystemClock;
use LexNova\Twig\EmailExtension;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$extension = new EmailExtension(new SystemClock());

$payloads = [
    '<script>alert(1)</script>',
    '<img src=x onerror=alert(1)>',
    '" autofocus onfocus="alert(1)',
    "javascript:alert('x') & text",
    "mail@example.org\nSecond line",
];

foreach ($payloads as $payload) {
    $encoded = $extension->obfuscate($payload);

    if (preg_match('/[<>"\'&](?!#(?:x[0-9a-f]+|[0-9]+);)/i', $encoded) === 1) {
        throw new RuntimeException('Obfuscated output contains an unsafe literal character.');
    }

    if (html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5, 'UTF-8') !== $payload) {
        throw new RuntimeException('Obfuscated output no longer represents the original text.');
    }
}

$link = $extension->mailto('alice+legal@example.org', 'example.org');
if (!str_starts_with($link, '<a href="') || !str_ends_with($link, '</a>')) {
    throw new RuntimeException('Mail link output has an unexpected structure.');
}

echo "Email extension security test: OK\n";
