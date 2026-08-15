<?php

declare(strict_types=1);

use LexNova\Service\TranslationService;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$translator = new TranslationService($root . '/resources/translations', 'de-DE');
if ($translator->translate('Imprint') !== 'Impressum') {
    throw new RuntimeException('Language fallback from de-DE to de failed.');
}
if ($translator->translate('Unknown message') !== 'Unknown message') {
    throw new RuntimeException('Missing translation did not fall back to its message key.');
}
if ($translator->translate('Imprint', '../../de') !== 'Imprint') {
    throw new RuntimeException('Invalid locale input reached a translation catalogue.');
}

echo "Translation service integration test: OK\n";
