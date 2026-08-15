<?php

declare(strict_types=1);

use LexNova\Handler\Install\Step\PrerequisiteCheck;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$requires = $composer['require'] ?? [];

$expectedExtensions = [
    'ext-ctype' => '*',
    'ext-fileinfo' => '*',
    'ext-filter' => '*',
    'ext-intl' => '*',
    'ext-json' => '*',
    'ext-mbstring' => '*',
    'ext-openssl' => '*',
    'ext-pdo' => '*',
    'ext-redis' => '^6.0',
    'ext-sodium' => '*',
];
$composerExtensions = array_filter(
    $requires,
    static fn (string $package): bool => str_starts_with($package, 'ext-'),
    ARRAY_FILTER_USE_KEY,
);

if ($composerExtensions !== $expectedExtensions) {
    throw new RuntimeException('composer.json PHP extension requirements changed without updating the platform test.');
}

$checkedExtensions = array_keys(PrerequisiteCheck::REQUIRED_EXTENSIONS);
$composerExtensionNames = array_map(
    static fn (string $package): string => substr($package, 4),
    array_keys($composerExtensions),
);
if ($checkedExtensions !== $composerExtensionNames) {
    throw new RuntimeException('Installer extension checks do not match composer.json.');
}

if (($requires['php'] ?? null) !== '>=' . PrerequisiteCheck::MINIMUM_PHP_VERSION) {
    throw new RuntimeException('Installer PHP version check does not match composer.json.');
}

if (PrerequisiteCheck::isExtensionSupported('redis', true, '5.3.7')) {
    throw new RuntimeException('PhpRedis below version 6 was accepted.');
}
if (!PrerequisiteCheck::isExtensionSupported('redis', true, '6.0.0')) {
    throw new RuntimeException('PhpRedis 6 was rejected.');
}
if (PrerequisiteCheck::isExtensionSupported('redis', false, null)) {
    throw new RuntimeException('A missing required extension was accepted.');
}
if (!PrerequisiteCheck::isExtensionSupported('sodium', true, null)) {
    throw new RuntimeException('A loaded extension without a minimum version was rejected.');
}
if (PrerequisiteCheck::isExtensionSupported('unknown', true, '99.0.0')) {
    throw new RuntimeException('An extension outside the declared requirement list was accepted.');
}

echo "Platform requirements configuration test: OK\n";
