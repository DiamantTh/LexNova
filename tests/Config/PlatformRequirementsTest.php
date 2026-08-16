<?php

declare(strict_types=1);

use LexNova\Handler\Install\Step\PrerequisiteCheck;
use Composer\InstalledVersions;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$requires = $composer['require'] ?? [];

foreach (['require', 'require-dev', 'suggest'] as $composerSection) {
    $composerPlatformRequirements = array_filter(
        $composer[$composerSection] ?? [],
        static fn (string $package): bool => $package === 'php' || str_starts_with($package, 'ext-'),
        ARRAY_FILTER_USE_KEY,
    );
    if ($composerPlatformRequirements !== []) {
        throw new RuntimeException('Root Composer dependency sections must contain Packagist packages only.');
    }
}

$expectedInstallerExtensions = [
    'ctype',
    'fileinfo',
    'filter',
    'intl',
    'json',
    'mbstring',
    'openssl',
    'pdo',
    'sodium',
];
if (array_keys(PrerequisiteCheck::REQUIRED_EXTENSIONS) !== $expectedInstallerExtensions
    || PrerequisiteCheck::MINIMUM_PHP_VERSION !== '8.4.1'
) {
    throw new RuntimeException('Installer platform requirements changed unexpectedly.');
}

if (PrerequisiteCheck::OPTIONAL_EXTENSIONS !== ['redis' => '6.0.0']
    || ($composer['suggest']['laminas/laminas-cache-storage-adapter-redis'] ?? null) === null
) {
    throw new RuntimeException('Optional Valkey requirements are not declared consistently.');
}

if (PrerequisiteCheck::isExtensionSupported('redis', true, '5.3.7')) {
    throw new RuntimeException('PhpRedis below version 6 was accepted.');
}
if (!PrerequisiteCheck::isExtensionSupported('redis', true, '6.0.0')) {
    throw new RuntimeException('PhpRedis 6 was rejected.');
}
if (PrerequisiteCheck::isExtensionSupported('redis', false, null)) {
    throw new RuntimeException('A missing optional extension was reported as available.');
}
if (!PrerequisiteCheck::isExtensionSupported('sodium', true, null)) {
    throw new RuntimeException('A loaded extension without a minimum version was rejected.');
}
if (!PrerequisiteCheck::isExtensionSupported('sodium', false, null, true)) {
    throw new RuntimeException('The declared Sodium polyfill was rejected.');
}
foreach (['ctype', 'mbstring'] as $polyfilledExtension) {
    if (!PrerequisiteCheck::isExtensionSupported($polyfilledExtension, false, null, true)) {
        throw new RuntimeException('The declared polyfill was rejected: ' . $polyfilledExtension);
    }
}
if (PrerequisiteCheck::isExtensionSupported('redis', false, null, true)) {
    throw new RuntimeException('An undeclared Redis polyfill was accepted.');
}
if (PrerequisiteCheck::isExtensionSupported('unknown', true, '99.0.0')) {
    throw new RuntimeException('An extension outside the declared requirement list was accepted.');
}

$expectedPolyfills = [
    'symfony/polyfill-ctype' => '^1.37',
    'symfony/polyfill-mbstring' => '^1.38',
    'paragonie/sodium_compat' => '^1.21 || ^2.5',
    'paragonie/sodium_compat_ext_sodium' => '^1.0',
];
foreach ($expectedPolyfills as $package => $constraint) {
    if (($requires[$package] ?? null) !== $constraint || !InstalledVersions::isInstalled($package)) {
        throw new RuntimeException('Required polyfill package is missing or has an unexpected constraint: ' . $package);
    }
}

echo "Platform requirements configuration test: OK\n";
