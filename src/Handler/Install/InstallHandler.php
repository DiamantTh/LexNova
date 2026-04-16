<?php

declare(strict_types=1);

namespace LexNova\Handler\Install;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use LexNova\Service\InstallService;
use LexNova\Service\PasswordService;
use Mezzio\Template\TemplateRendererInterface;
use PDO;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

final readonly class InstallHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly InstallService $install,
        private readonly PasswordService $passwords,
        private readonly TemplateRendererInterface $renderer,
        private readonly array $config,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->install->isLocked()) {
            return new HtmlResponse('Installer is locked.', 404);
        }

        $errors            = [];
        $messages          = [];
        $generatedPassword = null;
        $installReady      = $this->install->readPasswordHash() !== null;
        $installerUnlocked = false;

        // Generate install password on first visit
        if (!$installReady) {
            $security          = $this->config['security']['password'] ?? [];
            $generatedPassword = $this->install->initializePassword($security);
            if ($generatedPassword === null) {
                $errors[] = 'Failed to generate install password. Check data/ directory permissions.';
            } else {
                $installReady = true;
                $messages[]   = 'Install password generated — copy it now, it will not be shown again.';
            }
        }

        $root            = dirname(dirname(__DIR__));
        $defaultDbPath   = $root . '/data/lexnova.sqlite';
        $action          = '';
        $dbType          = 'sqlite';
        $dbHost          = 'localhost';
        $dbName          = '';
        $dbPort          = '';
        $dbPath          = $defaultDbPath;
        $dbUser          = '';
        $dbPassword      = '';
        $adminUsername   = '';
        $adminPassword   = '';
        $adminConfirm    = '';
        $installPwInput  = '';

        if ($request->getMethod() === 'POST') {
            $body = (array) ($request->getParsedBody() ?? []);

            $action         = trim((string) ($body['action'] ?? ''));
            $dbType         = trim((string) ($body['db_type'] ?? 'sqlite'));
            $dbHost         = trim((string) ($body['db_host'] ?? 'localhost'));
            $dbName         = trim((string) ($body['db_name'] ?? ''));
            $dbPort         = trim((string) ($body['db_port'] ?? ''));
            $dbPath         = trim((string) ($body['db_path'] ?? $defaultDbPath));
            $dbUser         = trim((string) ($body['db_user'] ?? ''));
            $dbPassword     = trim((string) ($body['db_password'] ?? ''));
            $adminUsername  = trim((string) ($body['admin_username'] ?? ''));
            $adminPassword  = (string) ($body['admin_password'] ?? '');
            $adminConfirm   = (string) ($body['admin_password_confirm'] ?? '');
            $installPwInput = (string) ($body['install_pw'] ?? '');

            $storedHash = $this->install->readPasswordHash();

            if ($storedHash === null || !$this->install->verifyPassword($installPwInput, $storedHash)) {
                $errors[] = 'Invalid install password.';
            } else {
                $installerUnlocked = true;
            }

            if ($action === 'install' && $installerUnlocked) {
                $errors = array_merge($errors, $this->validateInstallInput(
                    $dbType, $dbHost, $dbName, $dbPath,
                    $adminUsername, $adminPassword, $adminConfirm,
                ));

                if ($this->install->configExists()) {
                    $errors[] = 'Configuration already exists. Remove config/config.php to reinstall.';
                }

                if ($errors === []) {
                    try {
                        $dsn      = $this->buildDsn($dbType, $dbHost, $dbName, $dbPort, $dbPath);
                        $pdoUser  = $dbUser !== '' ? $dbUser : null;
                        $pdoPass  = $dbPassword !== '' ? $dbPassword : null;
                        $pdo      = new PDO($dsn, $pdoUser, $pdoPass, [
                            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                            PDO::ATTR_EMULATE_PREPARES   => false,
                        ]);

                        $this->runSchema($pdo, $root . '/sql/schema.sql');

                        $security = $this->config['security']['password'] ?? [];
                        $hash     = password_hash(
                            $adminPassword,
                            $security['algo'] ?? PASSWORD_ARGON2ID,
                            $security['options'] ?? [],
                        );

                        if ($hash === false) {
                            throw new RuntimeException('Failed to hash admin password.');
                        }

                        $stmt = $pdo->prepare(
                            'INSERT INTO users (username, password_hash, role, created_at) VALUES (?, ?, ?, ?)'
                        );
                        $stmt->execute([$adminUsername, $hash, 'admin', date('Y-m-d H:i:s')]);

                        $configContent = $this->buildConfigFile($dsn, $pdoUser, $pdoPass, $root);
                        if (!$this->install->writeConfig($configContent)) {
                            throw new RuntimeException('Failed to write config file.');
                        }

                        $this->install->lock();

                        return new HtmlResponse($this->renderer->render('install/index', [
                            'errors'            => [],
                            'messages'          => [
                                'Installation complete. You can now log in at /admin.',
                                'Remove install/install.pw after verifying access.',
                            ],
                            'generatedPassword'  => null,
                            'installReady'       => true,
                            'installerUnlocked'  => false,
                            'locked'             => true,
                            'formData'           => [],
                        ]));

                    } catch (PDOException $e) {
                        $errors[] = 'Database error: ' . $e->getMessage();
                    } catch (Throwable $e) {
                        $errors[] = 'Installation failed: ' . $e->getMessage();
                    }
                }
            }
        }

        return new HtmlResponse($this->renderer->render('install/index', [
            'errors'           => $errors,
            'messages'         => $messages,
            'generatedPassword' => $generatedPassword,
            'installReady'     => $installReady,
            'installerUnlocked' => $installerUnlocked,
            'locked'           => false,
            'formData'         => compact(
                'dbType', 'dbHost', 'dbName', 'dbPort', 'dbPath',
                'dbUser', 'adminUsername',
            ),
        ]));
    }

    /** @return list<string> */
    private function validateInstallInput(
        string $dbType, string $dbHost, string $dbName, string $dbPath,
        string $adminUsername, string $adminPassword, string $adminConfirm,
    ): array {
        $errors = [];

        if (!in_array($dbType, ['sqlite', 'mysql', 'pgsql'], true)) {
            $errors[] = 'Unsupported database type.';
        } elseif ($dbType === 'sqlite' && $dbPath === '') {
            $errors[] = 'SQLite file path is required.';
        } elseif (in_array($dbType, ['mysql', 'pgsql'], true) && ($dbHost === '' || $dbName === '')) {
            $errors[] = 'Database host and name are required.';
        }

        if ($adminUsername === '') {
            $errors[] = 'Admin username is required.';
        }

        if ($adminPassword === '') {
            $errors[] = 'Admin password is required.';
        } elseif ($adminPassword !== $adminConfirm) {
            $errors[] = 'Admin passwords do not match.';
        } else {
            $pwError = $this->passwords->validate($adminPassword);
            if ($pwError !== null) {
                $errors[] = $pwError;
            }
        }

        return $errors;
    }

    private function buildDsn(
        string $type, string $host, string $name, string $port, string $path,
    ): string {
        if ($type === 'sqlite') {
            return 'sqlite:' . $path;
        }

        $portPart = $port !== '' ? ';port=' . $port : '';

        if ($type === 'mysql') {
            return "mysql:host={$host}{$portPart};dbname={$name};charset=utf8mb4";
        }

        // pgsql
        return "pgsql:host={$host}{$portPart};dbname={$name}";
    }

    private function runSchema(PDO $pdo, string $schemaPath): void
    {
        $sql = (string) file_get_contents($schemaPath);

        foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
            $stmt = trim(preg_replace('/^--.*$/m', '', $stmt));
            if ($stmt !== '') {
                $pdo->exec($stmt);
            }
        }
    }

    private function buildConfigFile(
        string $dsn, ?string $user, ?string $password, string $root,
    ): string {
        $dsnExported  = var_export($dsn, true);
        $userExported = var_export($user, true);
        $passExported = var_export($password, true);

        return <<<PHP
<?php

declare(strict_types=1);

\$root = dirname(__DIR__);

return [
    'app' => [
        'base_url' => '',
    ],
    'db' => [
        'dsn'      => {$dsnExported},
        'user'     => {$userExported},
        'password' => {$passExported},
        'options'  => [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ],
    ],
    'install' => [
        'lock'          => \$root . '/install/install.lock',
        'password_file' => \$root . '/install/install.pw',
        'config_file'   => __DIR__ . '/config.php',
    ],
    'log' => [
        'path'  => \$root . '/data/lexnova.log',
        'level' => 'warning',
    ],
    'session' => [
        'name'     => 'lexnova_session',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ],
    'security' => require __DIR__ . '/security.php',
];
PHP;
    }
}
