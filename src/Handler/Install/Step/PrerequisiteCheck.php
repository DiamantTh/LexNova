<?php

declare(strict_types=1);

namespace LexNova\Handler\Install\Step;

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
     * This list is the application-side counterpart of composer.json and is
     * deliberately public so a regression test can prevent the two lists from
     * drifting apart.
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
        'redis' => '6.0.0',
        'sodium' => null,
    ];

    public function __construct(private readonly string $rootDir)
    {
    }

    /**
     * @return array{checks: list<array{label:string, ok:bool, value:?string, required:bool}>, blocked: bool}
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
        ];

        // ── Required extensions ───────────────────────────────────────────
        foreach (self::REQUIRED_EXTENSIONS as $extension => $minimumVersion) {
            $loaded = extension_loaded($extension);
            $version = $loaded ? phpversion($extension) : false;
            $displayVersion = is_string($version) && $version !== '' ? $version : null;

            $checks[] = [
                'label' => 'ext-' . $extension
                    . ($minimumVersion !== null ? ' ≥ ' . $minimumVersion : ''),
                'ok' => self::isExtensionSupported($extension, $loaded, $displayVersion),
                'value' => $displayVersion ?? ($loaded ? 'geladen' : null),
                'required' => true,
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
            ];
        }

        $blocked = false;
        foreach ($checks as $check) {
            if (!$check['ok']) {
                $blocked = true;
                break;
            }
        }

        return ['checks' => $checks, 'blocked' => $blocked];
    }

    public static function isExtensionSupported(string $extension, bool $loaded, ?string $version): bool
    {
        if (!$loaded || !array_key_exists($extension, self::REQUIRED_EXTENSIONS)) {
            return false;
        }

        $minimumVersion = self::REQUIRED_EXTENSIONS[$extension] ?? null;
        if ($minimumVersion === null) {
            return true;
        }

        return $version !== null && version_compare($version, $minimumVersion, '>=');
    }
}
