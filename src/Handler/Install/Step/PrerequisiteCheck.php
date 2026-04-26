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
            'label' => 'PHP ≥ 8.4',
            'ok' => version_compare($phpVersion, '8.4.0', '>='),
            'value' => $phpVersion,
            'required' => true,
        ];

        // ── Required extensions ───────────────────────────────────────────
        foreach (['sodium', 'pdo', 'json', 'mbstring', 'openssl'] as $ext) {
            $checks[] = [
                'label' => 'ext-' . $ext,
                'ok' => extension_loaded($ext),
                'value' => null,
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
            'value' => count($pdoDrivers) > 0 ? implode(', pdo_', $pdoDrivers) : null,
            'required' => true,
        ];

        // ── Recommended extensions ────────────────────────────────────────
        $checks[] = [
            'label' => 'ext-intl (empfohlen)',
            'ok' => extension_loaded('intl'),
            'value' => null,
            'required' => false,
        ];

        // ── Directory writability ─────────────────────────────────────────
        $dirs = [
            'data' => true,
            'configs' => true,
            'cache' => false,
            'logs' => false,
        ];

        foreach ($dirs as $dir => $required) {
            $path = $this->rootDir . '/' . $dir;
            $ok = is_dir($path) && is_writable($path);
            $checks[] = [
                'label' => $dir . '/ schreibbar',
                'ok' => $ok,
                'value' => null,
                'required' => $required,
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
}
