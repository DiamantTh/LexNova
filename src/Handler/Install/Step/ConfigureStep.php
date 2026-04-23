<?php

declare(strict_types=1);

namespace LexNova\Handler\Install\Step;

use LexNova\Service\InstallService;
use LexNova\Service\PasswordService;
use Locale;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Validates all installation inputs, creates the database schema, inserts the
 * admin user, writes config/config.php and locks the installer.
 *
 * BCP 47 locale validation uses PHP's ext-intl Locale class, which is a
 * hard dependency of laminas/laminas-i18n and therefore always available.
 */
final class ConfigureStep
{
    /**
     * @param  array<string, string> $formData
     * @param  array<string, mixed>  $securityConfig
     * @return array{errors: list<string>, completed: bool}
     */
    public function handle(
        InstallService  $install,
        PasswordService $passwords,
        array           $formData,
        array           $securityConfig,
        string          $root,
    ): array {
        $errors = $this->validate($formData, $passwords);

        if ($install->configExists()) {
            $errors[] = 'Configuration already exists. Remove config/config.php to reinstall.';
        }

        if ($errors !== []) {
            return ['errors' => $errors, 'completed' => false];
        }

        try {
            $dsn     = $this->buildDsn($formData);
            $pdoUser = $formData['dbUser'] !== '' ? $formData['dbUser'] : null;
            $pdoPass = $formData['dbPassword'] !== '' ? $formData['dbPassword'] : null;

            $pdo = new PDO($dsn, $pdoUser, $pdoPass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            $this->runSchema($pdo, $root . '/sql/schema.sql');

            $hash = password_hash(
                $formData['adminPassword'],
                $securityConfig['algo'] ?? PASSWORD_ARGON2ID,
                $securityConfig['options'] ?? [],
            );

            if ($hash === false) {
                throw new RuntimeException('Failed to hash admin password.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO users (username, password_hash, role, created_at) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$formData['adminUsername'], $hash, 'admin', date('Y-m-d H:i:s')]);

            $configContent = $this->buildConfigFile(
                $dsn,
                $pdoUser,
                $pdoPass,
                $formData['appLocale'],
                $root,
            );

            if (!$install->writeConfig($configContent)) {
                throw new RuntimeException('Failed to write config file.');
            }

            $install->lock();

        } catch (PDOException $e) {
            return ['errors' => ['Database error: ' . $e->getMessage()], 'completed' => false];
        } catch (Throwable $e) {
            return ['errors' => ['Installation failed: ' . $e->getMessage()], 'completed' => false];
        }

        return ['errors' => [], 'completed' => true];
    }

    /** @return list<string> */
    private function validate(array $formData, PasswordService $passwords): array
    {
        $errors = [];

        // ── Database ───────────────────────────────────────────────────────
        $dbType = $formData['dbType'] ?? '';
        $dbHost = $formData['dbHost'] ?? '';
        $dbName = $formData['dbName'] ?? '';
        $dbPath = $formData['dbPath'] ?? '';

        if (!in_array($dbType, ['sqlite', 'mysql', 'pgsql'], true)) {
            $errors[] = 'Unsupported database type.';
        } elseif ($dbType === 'sqlite' && $dbPath === '') {
            $errors[] = 'SQLite file path is required.';
        } elseif (in_array($dbType, ['mysql', 'pgsql'], true) && ($dbHost === '' || $dbName === '')) {
            $errors[] = 'Database host and name are required.';
        }

        // ── Admin account ──────────────────────────────────────────────────
        $adminUsername = $formData['adminUsername'] ?? '';
        $adminPassword = $formData['adminPassword'] ?? '';
        $adminConfirm  = $formData['adminConfirm']  ?? '';

        if ($adminUsername === '') {
            $errors[] = 'Admin username is required.';
        }

        if ($adminPassword === '') {
            $errors[] = 'Admin password is required.';
        } elseif ($adminPassword !== $adminConfirm) {
            $errors[] = 'Admin passwords do not match.';
        } else {
            $pwError = $passwords->validate($adminPassword);
            if ($pwError !== null) {
                $errors[] = $pwError;
            }
        }

        // ── App locale — BCP 47 via ext-intl (laminas/laminas-i18n dep) ───
        $appLocale = $formData['appLocale'] ?? '';

        if ($appLocale === '') {
            $errors[] = 'Application locale is required (e.g. de, en-US).';
        } elseif (!$this->isValidBcp47($appLocale)) {
            $errors[] = 'Application locale must be a valid BCP 47 tag (e.g. de, en-US, fr-CH).';
        }

        return $errors;
    }

    /**
     * Validates a BCP 47 language tag.
     *
     * Uses PHP ext-intl's Locale::parseLocale() (required by laminas/laminas-i18n)
     * to confirm the language subtag is recognised, combined with a structural
     * regex to prevent degenerate inputs the ICU parser may silently accept.
     */
    private function isValidBcp47(string $tag): bool
    {
        if (!preg_match('/^[a-zA-Z]{2,8}(-[a-zA-Z0-9]{1,8})*$/', $tag)) {
            return false;
        }

        $parsed = Locale::parseLocale($tag);

        return isset($parsed['language']);
    }

    private function buildDsn(array $formData): string
    {
        $type = $formData['dbType'];
        $host = $formData['dbHost'];
        $name = $formData['dbName'];
        $port = $formData['dbPort'];
        $path = $formData['dbPath'];

        if ($type === 'sqlite') {
            return 'sqlite:' . $path;
        }

        $portPart = $port !== '' ? ';port=' . $port : '';

        if ($type === 'mysql') {
            return "mysql:host={$host}{$portPart};dbname={$name};charset=utf8mb4";
        }

        return "pgsql:host={$host}{$portPart};dbname={$name}";
    }

    private function runSchema(PDO $pdo, string $schemaPath): void
    {
        $sql = (string) file_get_contents($schemaPath);

        foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
            $stmt = trim((string) preg_replace('/^--.*$/m', '', $stmt));
            if ($stmt !== '') {
                $pdo->exec($stmt);
            }
        }
    }

    private function buildConfigFile(
        string  $dsn,
        ?string $user,
        ?string $password,
        string  $appLocale,
        string  $root,
    ): string {
        return toml_encode([
            'app' => [
                'base_url' => '',
                'locale'   => $appLocale,
            ],
            'db' => [
                'dsn'      => $dsn,
                'user'     => $user ?? '',
                'password' => $password ?? '',
            ],
            'install' => [
                'lock'          => $root . '/install/install.lock',
                'password_file' => $root . '/install/install.pw',
                'config_file'   => $root . '/config/config.toml',
            ],
            'log' => [
                'path'  => $root . '/data/lexnova.log',
                'level' => 'warning',
            ],
            'session' => [
                'name'     => 'lexnova_session',
                'secure'   => false,
                'httponly' => true,
                'samesite' => 'Lax',
            ],
        ]);
    }
}
