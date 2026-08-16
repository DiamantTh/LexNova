<?php

declare(strict_types=1);

namespace LexNova\Handler\Install\Step;

use LexNova\Service\CacheBackendService;
use LexNova\Service\InstallService;
use LexNova\Service\PasswordService;
use Locale;
use Psr\Log\LoggerInterface;

/**
 * Validates all installation inputs, creates the database schema, inserts the
 * admin user, writes config/config.php and locks the installer.
 *
 * BCP 47 locale validation uses PHP's ext-intl Locale class, which is a
 * declared runtime dependency and therefore available on supported hosts.
 */
final class ConfigureStep
{
    /**
     * @param  array<string, string>                                                $formData
     * @param  array<string, mixed>                                                 $securityConfig
     * @return array{errors: list<string>, completed: bool, operator_name?: string}
     */
    public function handle(
        InstallService $install,
        PasswordService $passwords,
        array $formData,
        array $securityConfig,
        string $root,
        LoggerInterface $logger,
    ): array {
        $errors = $this->validate($formData, $passwords);

        if ($install->configExists()) {
            $errors[] = 'Configuration already exists. Remove config/config.toml to reinstall.';
        }

        if ($errors !== []) {
            return ['errors' => $errors, 'completed' => false];
        }

        $lockHandle = fopen($root . '/data/install.run.lock', 'c+');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            if (is_resource($lockHandle)) {
                fclose($lockHandle);
            }

            return ['errors' => ['Another installation process is already running.'], 'completed' => false];
        }

        try {
            if ($install->configExists()) {
                return ['errors' => ['Configuration already exists. Remove config/config.toml to reinstall.'], 'completed' => false];
            }

            $dsn = $this->buildDsn($formData);
            $pdoUser = $formData['dbUser'] !== '' ? $formData['dbUser'] : null;
            $pdoPass = $formData['dbPassword'] !== '' ? $formData['dbPassword'] : null;

            $pdo = new \PDO($dsn, $pdoUser, $pdoPass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $this->runSchema($pdo, $root . '/sql/schema.' . $formData['dbType'] . '.sql');

            $hash = password_hash(
                $formData['adminPassword'],
                $securityConfig['algo'] ?? PASSWORD_ARGON2ID,
                $securityConfig['options'] ?? [],
            );

            if ($hash === false) { // @phpstan-ignore identical.alwaysFalse
                throw new \RuntimeException('Failed to hash admin password.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO users (username, password_hash, role, created_at) VALUES (?, ?, ?, ?)',
            );
            $stmt->execute([$formData['adminUsername'], $hash, 'admin', date('Y-m-d H:i:s')]);

            // ── Operator entity ───────────────────────────────────────────
            $operatorHash = bin2hex(random_bytes(16)); // 32 hex chars
            $operatorContact = str_replace(["\r\n", "\r"], "\n", $formData['operatorContact']);
            $stmt = $pdo->prepare(
                'INSERT INTO legal_entities (hash, name, contact_data) VALUES (?, ?, ?)',
            );
            $stmt->execute([$operatorHash, $formData['operatorName'], $operatorContact]);

            $configContent = $this->buildConfigFile(
                $formData,
                $formData['appBaseUrl'],
                $formData['appLocale'],
                sodium_bin2hex(random_bytes(32)),
                $root,
            );

            if (!$install->writeConfig($configContent)) {
                throw new \RuntimeException('Failed to write config file.');
            }

            $install->lock();
        } catch (\PDOException $e) {
            $logger->error('LexNova installation database setup failed.', ['exception' => $e]);

            return ['errors' => ['Database setup failed. Check the connection details and server log.'], 'completed' => false];
        } catch (\Throwable $e) {
            $logger->error('LexNova installation failed.', ['exception' => $e]);

            return ['errors' => ['Installation failed. Check the server log for details.'], 'completed' => false];
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }

        return [
            'errors' => [],
            'completed' => true,
            'operator_name' => $formData['operatorName'],
        ];
    }

    /**
     * @param  array<string, mixed> $formData
     * @return list<string>
     */
    private function validate(array $formData, PasswordService $passwords): array
    {
        $errors = [];

        // ── Database ───────────────────────────────────────────────────────
        $dbType = $formData['dbType'] ?? '';
        $dbHost = $formData['dbHost'] ?? '';
        $dbName = $formData['dbName'] ?? '';
        $dbPath = $formData['dbPath'] ?? '';
        $dbPort = $formData['dbPort'] ?? '';

        if (!in_array($dbType, ['sqlite', 'mysql', 'pgsql'], true)) {
            $errors[] = 'Unsupported database type.';
        } elseif ($dbType === 'sqlite' && $dbPath === '') {
            $errors[] = 'SQLite file path is required.';
        } elseif (in_array($dbType, ['mysql', 'pgsql'], true) && ($dbHost === '' || $dbName === '')) {
            $errors[] = 'Database host and name are required.';
        } elseif (in_array($dbType, ['mysql', 'pgsql'], true)
            && preg_match('/^[a-zA-Z0-9_.:-]{1,255}$/D', $dbHost) !== 1
        ) {
            $errors[] = 'Database host contains unsupported characters.';
        } elseif (in_array($dbType, ['mysql', 'pgsql'], true)
            && preg_match('/^[a-zA-Z0-9_.-]{1,128}$/D', $dbName) !== 1
        ) {
            $errors[] = 'Database name contains unsupported characters.';
        } elseif ($dbPort !== '' && (filter_var($dbPort, FILTER_VALIDATE_INT) === false
            || (int) $dbPort < 1 || (int) $dbPort > 65535)
        ) {
            $errors[] = 'Database port must be between 1 and 65535.';
        }

        // ── Admin account ──────────────────────────────────────────────────
        $adminUsername = $formData['adminUsername'] ?? '';
        $adminPassword = $formData['adminPassword'] ?? '';
        $adminConfirm = $formData['adminConfirm'] ?? '';

        if (preg_match('/^[a-zA-Z0-9_.@+-]{3,100}$/D', $adminUsername) !== 1) {
            $errors[] = 'Admin username must be 3–100 characters and may contain letters, digits, ., _, @, + and -.';
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

        // ── App locale — BCP 47 via ext-intl ───────────────────────────────
        $appBaseUrl = $formData['appBaseUrl'] ?? '';
        if (!$this->isValidBaseUrl($appBaseUrl)) {
            $errors[] = 'A public HTTPS base URL is required for secure sessions and passkeys (http://localhost is allowed for local development).';
        }

        $appLocale = $formData['appLocale'] ?? '';

        if ($appLocale === '') {
            $errors[] = 'Application locale is required (e.g. de, en-US).';
        } elseif (!$this->isValidBcp47($appLocale)) {
            $errors[] = 'Application locale must be a valid BCP 47 tag (e.g. de, en-US, fr-CH).';
        }

        // ── Operator entity ────────────────────────────────────────────────
        $operatorName = $formData['operatorName'] ?? '';
        $operatorContact = $formData['operatorContact'] ?? '';

        if ($operatorName === '') {
            $errors[] = 'Operator name is required (for your own imprint / privacy page).';
        } elseif (mb_strlen($operatorName, 'UTF-8') > 255) {
            $errors[] = 'Operator name must not exceed 255 characters.';
        }

        if ($operatorContact === '') {
            $errors[] = 'Operator contact data is required (address, e-mail, etc.).';
        } elseif (strlen($operatorContact) > 65535) {
            $errors[] = 'Operator contact data must not exceed 65535 bytes.';
        }

        // ── Application cache ─────────────────────────────────────────────
        $cacheAdapter = $formData['cacheAdapter'] ?? 'filesystem';
        if (!in_array($cacheAdapter, ['filesystem', 'apcu', 'valkey'], true)) {
            $errors[] = 'Unsupported cache adapter.';
        } elseif ($cacheAdapter === 'apcu' && !CacheBackendService::apcuClientAvailable()) {
            $errors[] = 'APCu is not usable in the current web SAPI or the optional Laminas APCu adapter is missing.';
        } elseif ($cacheAdapter === 'valkey' && !CacheBackendService::valkeyClientAvailable()) {
            $errors[] = 'Valkey requires PhpRedis 6+ and the optional Laminas Redis adapter.';
        }

        if ($cacheAdapter === 'valkey') {
            $cacheHost = $formData['cacheHost'] ?? '';
            $cachePort = $formData['cachePort'] ?? '';
            $cacheDatabase = $formData['cacheDatabase'] ?? '';
            if (preg_match('/^[a-zA-Z0-9_.:-]{1,255}$/D', $cacheHost) !== 1) {
                $errors[] = 'Valkey host contains unsupported characters.';
            }
            if (filter_var($cachePort, FILTER_VALIDATE_INT) === false
                || (int) $cachePort < 1 || (int) $cachePort > 65535
            ) {
                $errors[] = 'Valkey port must be between 1 and 65535.';
            }
            if (filter_var($cacheDatabase, FILTER_VALIDATE_INT) === false || (int) $cacheDatabase < 0) {
                $errors[] = 'Valkey database must be a non-negative integer.';
            }
            if (strlen($formData['cacheUsername'] ?? '') > 255) {
                $errors[] = 'Valkey username must not exceed 255 characters.';
            }
        }

        return $errors;
    }

    /**
     * Validates a BCP 47 language tag.
     *
     * Uses PHP ext-intl's Locale::parseLocale() if available. If ext-intl is not
     * loaded, falls back to a structural regex check only (install will still
     * warn about the missing extension via PrerequisiteCheck).
     */
    private function isValidBcp47(string $tag): bool
    {
        if (!preg_match('/^[a-zA-Z]{2,8}(-[a-zA-Z0-9]{1,8})*$/', $tag)) {
            return false;
        }

        if (!extension_loaded('intl')) {
            return true; // structural check passed, intl not available for deeper validation
        }

        $parsed = \Locale::parseLocale($tag);

        return isset($parsed['language']);
    }

    private function isValidBaseUrl(string $url): bool
    {
        $parsed = parse_url($url);
        if (!is_array($parsed)
            || !isset($parsed['scheme'], $parsed['host'])
            || isset($parsed['query'], $parsed['fragment'], $parsed['user'], $parsed['pass'])
            || (isset($parsed['path']) && $parsed['path'] !== '' && $parsed['path'] !== '/')
        ) {
            return false;
        }

        if ($parsed['scheme'] === 'https') {
            return true;
        }

        return $parsed['scheme'] === 'http' && in_array($parsed['host'], ['localhost', '127.0.0.1', '::1'], true);
    }

    /** @param array<string, mixed> $formData */
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

    private function runSchema(\PDO $pdo, string $schemaPath): void
    {
        $sql = (string) file_get_contents($schemaPath);

        foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
            $stmt = trim((string) preg_replace('/^--.*$/m', '', $stmt));
            if ($stmt !== '') {
                $pdo->exec($stmt);
            }
        }
    }

    /** @param array<string, string> $formData */
    private function buildConfigFile(
        array $formData,
        string $appBaseUrl,
        string $appLocale,
        string $totpAppKey,
        string $root,
    ): string {
        $database = $formData['dbType'] === 'sqlite'
            ? [
                'driver' => 'sqlite',
                'path' => $formData['dbPath'],
            ]
            : [
                'driver' => $formData['dbType'],
                'host' => $formData['dbHost'],
                'port' => $formData['dbPort'] !== ''
                    ? (int) $formData['dbPort']
                    : ($formData['dbType'] === 'mysql' ? 3306 : 5432),
                'name' => $formData['dbName'],
                'user' => $formData['dbUser'],
                'password' => $formData['dbPassword'],
            ];
        if ($formData['dbType'] === 'mysql') {
            $database['charset'] = 'utf8mb4';
        }

        $cacheAdapter = $formData['cacheAdapter'] ?? 'filesystem';
        $cache = [
            'adapter' => $cacheAdapter,
            // Random, non-secret installation identifier prevents collisions
            // when several LexNova instances share one APCu/Valkey namespace.
            'namespace' => 'lexnova.' . bin2hex(random_bytes(6)),
            'default_ttl' => 3600,
        ];
        if ($cacheAdapter === 'valkey') {
            $cache += [
                'host' => $formData['cacheHost'] ?? '127.0.0.1',
                'port' => (int) ($formData['cachePort'] ?? 6379),
                'database' => (int) ($formData['cacheDatabase'] ?? 0),
                'username' => $formData['cacheUsername'] ?? '',
                'password' => $formData['cachePassword'] ?? '',
                'tls' => ($formData['cacheTls'] ?? '0') === '1',
            ];
        }

        return toml_encode([
            'app' => [
                'base_url' => rtrim($appBaseUrl, '/'),
                'locale' => $appLocale,
            ],
            'security' => [
                // XSalsa20-Poly1305 key for TOTP secret encryption (32 bytes / 64 hex chars).
                // Do NOT change after TOTP secrets are enrolled — it will invalidate them.
                'totp_app_key' => $totpAppKey,
                'fail2ban' => [
                    'enabled' => false,
                    'path' => 'var/log/fail2ban.log',
                    'settings_cache_ttl' => 60,
                ],
            ],
            'db' => $database,
            'install' => [
                'lock' => $root . '/data/install.lock',
                'password_file' => $root . '/data/install.pw',
                'config_file' => $root . '/config/config.toml',
            ],
            'log' => [
                'path' => $root . '/var/log/lexnova.log',
                'level' => 'warning',
            ],
            'session' => [
                'name' => 'lexnova_session',
                'secure' => str_starts_with($appBaseUrl, 'https://'),
                'httponly' => true,
                'samesite' => 'Strict',
                'cookie_lifetime' => 0,
                'cookie_path' => '/',
            ],
            'cache' => $cache,
        ]);
    }
}
