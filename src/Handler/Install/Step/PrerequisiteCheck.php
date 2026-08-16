<?php

declare(strict_types=1);

namespace LexNova\Handler\Install\Step;

use Composer\InstalledVersions;

/**
 * Checks all server prerequisites before the installer may proceed.
 *
 * Each check entry:
 *   label    string   Human-readable name
 *   ok       bool     Whether the check passed
 *   value    ?string  Optional current value shown to the user (e.g. PHP version)
 *   required bool     If true and ok=false, installation cannot proceed
 */
final class PrerequisiteCheck
{
    public const MINIMUM_PHP_VERSION = '8.4.1';

    /**
     * A null version means that any loaded version is accepted.
     *
     * Platform requirements deliberately live in the installer instead of the
     * root Composer package list. The constant is public so regression tests
     * can protect the complete user-facing check independently of Composer.
     *
     * @var array<string, ?string>
     */
    public const REQUIRED_EXTENSIONS = [
        'ctype' => null,
        'fileinfo' => null,
        'filter' => null,
        'intl' => null,
        'json' => null,
        'mbstring' => null,
        'openssl' => null,
        'pdo' => null,
        'sodium' => null,
    ];

    /** @var array<string, ?string> */
    public const OPTIONAL_EXTENSIONS = [
        'apcu' => '5.1.10',
        'redis' => '6.0.0',
    ];

    /** @var array<string, string> */
    public const OPTIONAL_PACKAGES = [
        'laminas/laminas-cache-storage-adapter-apcu' => 'Laminas APCu cache adapter',
        'laminas/laminas-cache-storage-adapter-redis' => 'Laminas Valkey/Redis cache adapter',
    ];

    /**
     * Only packages that Composer recognizes as providers for the matching
     * ext-* capability belong here. Native extensions remain preferred.
     *
     * @var array<string, array{package:string, functions:list<string>, constants:list<string>}>
     */
    public const EXTENSION_POLYFILLS = [
        'ctype' => [
            'package' => 'symfony/polyfill-ctype',
            'functions' => ['ctype_alnum', 'ctype_alpha', 'ctype_digit', 'ctype_space', 'ctype_xdigit'],
            'constants' => [],
        ],
        'mbstring' => [
            'package' => 'symfony/polyfill-mbstring',
            'functions' => ['mb_strlen', 'mb_substr', 'mb_ord'],
            'constants' => [],
        ],
        'sodium' => [
            'package' => 'paragonie/sodium_compat',
            'functions' => [
                'sodium_bin2hex',
                'sodium_hex2bin',
                'sodium_crypto_secretbox',
                'sodium_crypto_secretbox_open',
            ],
            'constants' => ['SODIUM_CRYPTO_SECRETBOX_NONCEBYTES'],
        ],
    ];

    public function __construct(private readonly string $rootDir)
    {
    }

    /**
     * @return array{checks: list<array{label:string, ok:bool, value:?string, required:bool, fallback:bool}>, blocked: bool}
     */
    public function run(): array
    {
        $checks = [];

        // ── PHP version ───────────────────────────────────────────────────
        $phpVersion = PHP_VERSION;
        $checks[] = [
            'label' => 'PHP ≥ ' . self::MINIMUM_PHP_VERSION,
            'ok' => version_compare($phpVersion, self::MINIMUM_PHP_VERSION, '>='),
            'value' => $phpVersion,
            'required' => true,
            'fallback' => false,
        ];

        // ── Required extensions ───────────────────────────────────────────
        foreach (self::REQUIRED_EXTENSIONS + self::OPTIONAL_EXTENSIONS as $extension => $minimumVersion) {
            $loaded = extension_loaded($extension);
            $version = $loaded ? phpversion($extension) : false;
            $displayVersion = is_string($version) && $version !== '' ? $version : null;
            $polyfillAvailable = !$loaded && self::isPolyfillAvailable($extension);
            $required = array_key_exists($extension, self::REQUIRED_EXTENSIONS);

            $checks[] = [
                'label' => 'ext-' . $extension
                    . ($minimumVersion !== null ? ' ≥ ' . $minimumVersion : ''),
                'ok' => self::isExtensionSupported($extension, $loaded, $displayVersion, $polyfillAvailable),
                'value' => $displayVersion
                    ?? ($polyfillAvailable ? self::polyfillDescription($extension) : ($loaded ? 'geladen' : null)),
                'required' => $required,
                'fallback' => $polyfillAvailable,
            ];
        }

        // ── Optional Composer cache adapters ──────────────────────────────
        foreach (self::OPTIONAL_PACKAGES as $package => $label) {
            $installed = InstalledVersions::isInstalled($package);
            $checks[] = [
                'label' => $label,
                'ok' => $installed,
                'value' => $installed ? InstalledVersions::getPrettyVersion($package) : $package,
                'required' => false,
                'fallback' => false,
            ];
        }

        // ── PDO database driver (at least one) ────────────────────────────
        $pdoDrivers = extension_loaded('pdo')
            ? array_values(array_intersect(['sqlite', 'mysql', 'pgsql'], \PDO::getAvailableDrivers()))
            : [];

        $checks[] = [
            'label' => 'PDO-Treiber (sqlite / mysql / pgsql)',
            'ok' => count($pdoDrivers) > 0,
            'value' => count($pdoDrivers) > 0
                ? implode(', ', array_map(static fn (string $driver): string => 'pdo_' . $driver, $pdoDrivers))
                : null,
            'required' => true,
            'fallback' => false,
        ];

        // ── Directory writability ─────────────────────────────────────────
        $dirs = [
            'data' => true,
            'config' => true,
            'var' => true,
        ];

        foreach ($dirs as $dir => $required) {
            $path = $this->rootDir . '/' . $dir;
            // data/ is deliberately not versioned: create it only when an
            // installation actually starts. config/ ships with security.toml
            // and must already be writable so the generated instance config
            // can be placed beside it.
            $ok = is_dir($path)
                ? is_writable($path)
                : $dir === 'data' && mkdir($path, 0755, true) && is_writable($path);
            $checks[] = [
                'label' => $dir . '/ schreibbar',
                'ok' => $ok,
                'value' => null,
                'required' => $required,
                'fallback' => false,
            ];
        }

        $blocked = false;
        foreach ($checks as $check) {
            if ($check['required'] && !$check['ok']) {
                $blocked = true;
                break;
            }
        }

        return ['checks' => $checks, 'blocked' => $blocked];
    }

    /**
     * Reports adapter readiness for the current SAPI. A CLI result must not be
     * treated as proof that APCu is enabled for PHP-FPM or Apache, and vice versa.
     *
     * @return array<string, array{available: bool, reason: string}>
     */
    public function cacheAdapterSupport(): array
    {
        $apcuEnabled = function_exists('apcu_enabled') && apcu_enabled();
        $apcuVersion = extension_loaded('apcu') ? phpversion('apcu') : false;
        $apcuExtension = is_string($apcuVersion) && version_compare($apcuVersion, '5.1.10', '>=');
        $apcuPackage = InstalledVersions::isInstalled('laminas/laminas-cache-storage-adapter-apcu');
        $redisVersion = extension_loaded('redis') ? phpversion('redis') : false;
        $redisExtension = is_string($redisVersion) && version_compare($redisVersion, '6.0.0', '>=');
        $redisPackage = InstalledVersions::isInstalled('laminas/laminas-cache-storage-adapter-redis');

        return [
            'filesystem' => ['available' => true, 'reason' => 'Ohne zusätzliche Erweiterung verfügbar.'],
            'apcu' => [
                'available' => $apcuExtension && $apcuPackage && $apcuEnabled,
                'reason' => match (true) {
                    !$apcuExtension => 'APCu 5.1.10 oder neuer fehlt.',
                    !$apcuPackage => 'Der optionale Laminas-APCu-Adapter fehlt.',
                    !$apcuEnabled => 'APCu ist im aktuellen Web-SAPI deaktiviert.',
                    default => 'APCu und Laminas-Adapter sind im aktuellen Web-SAPI einsatzbereit.',
                },
            ],
            'valkey' => [
                'available' => $redisExtension && $redisPackage,
                'reason' => match (true) {
                    !$redisExtension => 'PhpRedis 6.0 oder neuer fehlt.',
                    !$redisPackage => 'Der optionale Laminas-Redis-Adapter fehlt.',
                    default => 'Client und Laminas-Adapter sind verfügbar; die Serververbindung wird zur Laufzeit geprüft.',
                },
            ],
        ];
    }

    public static function isExtensionSupported(
        string $extension,
        bool $loaded,
        ?string $version,
        bool $polyfillAvailable = false,
    ): bool {
        $extensions = self::REQUIRED_EXTENSIONS + self::OPTIONAL_EXTENSIONS;
        if (!array_key_exists($extension, $extensions)) {
            return false;
        }

        if (!$loaded) {
            return $polyfillAvailable && array_key_exists($extension, self::EXTENSION_POLYFILLS);
        }

        $minimumVersion = $extensions[$extension] ?? null;
        if ($minimumVersion === null) {
            return true;
        }

        return $version !== null && version_compare($version, $minimumVersion, '>=');
    }

    public static function isPolyfillAvailable(string $extension): bool
    {
        $polyfill = self::EXTENSION_POLYFILLS[$extension] ?? null;
        if ($polyfill === null || !InstalledVersions::isInstalled($polyfill['package'])) {
            return false;
        }

        foreach ($polyfill['functions'] as $function) {
            if (!function_exists($function)) {
                return false;
            }
        }
        foreach ($polyfill['constants'] as $constant) {
            if (!defined($constant)) {
                return false;
            }
        }

        return true;
    }

    private static function polyfillDescription(string $extension): ?string
    {
        $package = self::EXTENSION_POLYFILLS[$extension]['package'] ?? null;
        if ($package === null) {
            return null;
        }

        return 'Polyfill: ' . $package . ' ' . (InstalledVersions::getPrettyVersion($package) ?? 'unbekannt');
    }
}
