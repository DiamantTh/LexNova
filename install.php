<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

if (is_install_locked()) {
    http_response_code(404);
    echo 'Installer is disabled.';
    exit;
}

function build_db_dsn(string $type, string $host, string $name, string $port, string $path): string
{
    $type = strtolower(trim($type));
    $port = trim($port);

    if ($type === 'sqlite') {
        return $path !== '' ? 'sqlite:' . $path : '';
    }

    if ($type === 'mysql') {
        if ($host === '' || $name === '') {
            return '';
        }
        $dsn = 'mysql:host=' . $host . ';';
        if ($port !== '') {
            $dsn .= 'port=' . $port . ';';
        }
        return $dsn . 'dbname=' . $name . ';charset=utf8mb4';
    }

    if ($type === 'pgsql') {
        if ($host === '' || $name === '') {
            return '';
        }
        $dsn = 'pgsql:host=' . $host . ';';
        if ($port !== '') {
            $dsn .= 'port=' . $port . ';';
        }
        return $dsn . 'dbname=' . $name;
    }

    return '';
}

function build_config_file_contents(string $dbDsn, ?string $dbUser, ?string $dbPassword): string
{
    $root = dirname(__FILE__);

    return toml_encode([
        'app' => [
            'base_url' => '',
        ],
        'db' => [
            'dsn'      => $dbDsn,
            'user'     => $dbUser ?? '',
            'password' => $dbPassword ?? '',
        ],
        'install' => [
            'lock'          => $root . '/install/install.lock',
            'password_file' => $root . '/install/install.pw',
            'config_file'   => $root . '/config/config.toml',
        ],
        'session' => [
            'name'     => 'lexnova_session',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ],
    ]);
}

$errors = [];
$messages = [];
$installPasswordHash = read_install_password_hash();
$installReady = $installPasswordHash !== null;
$installerUnlocked = false;
$action = (string) ($_POST['action'] ?? '');
$generatedPassword = null;

if (!$installReady) {
    $passwordPath = install_password_path();
    if (is_file($passwordPath)) {
        $errors[] = 'Install password file exists but could not be read. Remove install/install.pw and retry.';
    } else {
        $installDir = dirname($passwordPath);
        if (!is_dir($installDir) && !mkdir($installDir, 0755, true) && !is_dir($installDir)) {
            $errors[] = 'Failed to create install directory.';
        } else {
            $config = app_config();
            $security = $config['security']['password'];
            $generatedPassword = bin2hex(random_bytes(8));
            $hash = password_hash($generatedPassword, $security['algo'], $security['options']);
            if ($hash === false) {
                $errors[] = 'Failed to hash install password.';
                $generatedPassword = null;
            } elseif (file_put_contents($passwordPath, $hash . "\n", LOCK_EX) === false) {
                $errors[] = 'Failed to write install password file.';
                $generatedPassword = null;
            } else {
                $installPasswordHash = $hash;
                $installReady = true;
            }
        }
    }
}

$defaultSqlitePath = app_root() . '/data/lexnova.sqlite';
$dbType = (string) ($_POST['db_type'] ?? 'sqlite');
$dbHost = (string) ($_POST['db_host'] ?? 'localhost');
$dbName = (string) ($_POST['db_name'] ?? '');
$dbPort = (string) ($_POST['db_port'] ?? '');
$dbPath = (string) ($_POST['db_path'] ?? $defaultSqlitePath);
$dbUser = (string) ($_POST['db_user'] ?? '');
$dbPassword = (string) ($_POST['db_password'] ?? '');
$adminUsername = (string) ($_POST['admin_username'] ?? '');
$adminPassword = (string) ($_POST['admin_password'] ?? '');
$adminPasswordConfirm = (string) ($_POST['admin_password_confirm'] ?? '');
$installPwInput = (string) ($_POST['install_pw'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid session token.';
    }

    if ($action === 'unlock') {
        if (!$installReady) {
            $errors[] = 'Install password not initialized. Reload the installer.';
        } elseif ($installPwInput === '') {
            $errors[] = 'Install password is required.';
        } elseif (!verify_install_password($installPwInput, $installPasswordHash ?? '')) {
            $errors[] = 'Invalid install password.';
        }

        if (!$errors) {
            $installerUnlocked = true;
            $messages[] = 'Installer unlocked.';
        }
    } elseif ($action === 'install') {
        if (!$installReady) {
            $errors[] = 'Install password not initialized. Reload the installer.';
        } elseif ($installPwInput === '') {
            $errors[] = 'Install password is required.';
        } elseif (!verify_install_password($installPwInput, $installPasswordHash ?? '')) {
            $errors[] = 'Invalid install password.';
        } else {
            $installerUnlocked = true;
        }

        $dbDsn = build_db_dsn($dbType, $dbHost, $dbName, $dbPort, $dbPath);

        if ($dbType === 'sqlite' && trim($dbPath) === '') {
            $errors[] = 'Database file is required.';
        } elseif (in_array($dbType, ['mysql', 'pgsql'], true) && (trim($dbHost) === '' || trim($dbName) === '')) {
            $errors[] = 'Database host and name are required.';
        } elseif (!in_array($dbType, ['sqlite', 'mysql', 'pgsql'], true)) {
            $errors[] = 'Unsupported database type.';
        } elseif ($dbDsn === '') {
            $errors[] = 'Database configuration is required.';
        }

        if (trim($adminUsername) === '') {
            $errors[] = 'Admin username is required.';
        }

        if ($adminPassword === '' || $adminPassword !== $adminPasswordConfirm) {
            $errors[] = 'Admin passwords do not match.';
        }

        if (is_file(config_file_path())) {
            $errors[] = 'Configuration already exists. Remove config/config.php to reinstall.';
        }

        if (!$errors) {
            $config = app_config();
            $dbOptions = $config['db']['options'];
            $security = $config['security']['password'];

            try {
                $pdo = new PDO($dbDsn, $dbUser !== '' ? $dbUser : null, $dbPassword !== '' ? $dbPassword : null, $dbOptions);

                $schemaPath = app_root() . '/sql/schema.sql';
                $schemaSql = file_get_contents($schemaPath);
                if ($schemaSql === false) {
                    throw new RuntimeException('Schema file not found.');
                }

                $lines = preg_split('/\r?\n/', $schemaSql);
                $cleanSql = '';
                foreach ($lines as $line) {
                    $trimmed = ltrim($line);
                    if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                        continue;
                    }
                    $cleanSql .= $line . "\n";
                }

                $statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $cleanSql)));
                foreach ($statements as $statement) {
                    $pdo->exec($statement);
                }

                $hash = password_hash($adminPassword, $security['algo'], $security['options']);
                if ($hash === false) {
                    throw new RuntimeException('Failed to hash admin password.');
                }

                $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, role, created_at) VALUES (:username, :hash, :role, :created_at)');
                $stmt->execute([
                    ':username' => $adminUsername,
                    ':hash' => $hash,
                    ':role' => 'admin',
                    ':created_at' => date('Y-m-d H:i:s'),
                ]);

                $installDir = dirname(install_password_path());
                if (!is_dir($installDir) && !mkdir($installDir, 0755, true) && !is_dir($installDir)) {
                    throw new RuntimeException('Failed to create install directory.');
                }

                $configDir = dirname(config_file_path());
                if (!is_dir($configDir) && !mkdir($configDir, 0755, true) && !is_dir($configDir)) {
                    throw new RuntimeException('Failed to create config directory.');
                }

                $databaseConfig = build_config_file_contents(
                    $dbDsn,
                    $dbUser !== '' ? $dbUser : null,
                    $dbPassword !== '' ? $dbPassword : null
                );

                if (file_put_contents(config_file_path(), $databaseConfig, LOCK_EX) === false) {
                    throw new RuntimeException('Failed to write configuration file.');
                }

                if (file_put_contents(install_lock_path(), 'installed ' . date('c') . "\n", LOCK_EX) === false) {
                    throw new RuntimeException('Failed to create install lock.');
                }

                $messages[] = 'Installation completed. Remove install/install.pw and proceed to admin.php.';
            } catch (Throwable $e) {
                $errors[] = 'Installation failed: ' . $e->getMessage();
            }
        }
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LexNova Installer</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f6f7fb;
            --card: #ffffff;
            --text: #1d2433;
            --muted: #5b6476;
            --border: #e2e7f0;
            --accent: #1f6feb;
            --accent-ink: #ffffff;
            --shadow: 0 18px 40px rgba(22, 38, 69, 0.12);
            --radius: 16px;
        }
        body {
            font-family: "IBM Plex Sans", "Segoe UI", "Helvetica Neue", sans-serif;
            margin: 0;
            background: var(--bg);
            color: var(--text);
        }
        .wrap {
            max-width: 960px;
            margin: 0 auto;
            padding: 28px 16px 60px;
        }
        .hero {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 16px;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--muted);
        }
        .layout {
            display: grid;
            grid-template-columns: minmax(220px, 320px) minmax(0, 1fr);
            gap: 16px;
            align-items: start;
        }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
        }
        h1 {
            margin: 0;
            font-size: 26px;
            letter-spacing: -0.02em;
        }
        h2 {
            margin: 0 0 12px;
            font-size: 16px;
        }
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 4px;
            color: var(--text);
        }
        input,
        select {
            width: 100%;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid var(--border);
            font-size: 13px;
            background: #fbfcff;
            color: var(--text);
        }
        input:focus,
        select:focus {
            outline: 2px solid rgba(31, 111, 235, 0.25);
            border-color: var(--accent);
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
        }
        .actions {
            margin-top: 12px;
            display: flex;
            gap: 12px;
        }
        button {
            background: var(--accent);
            color: var(--accent-ink);
            border: none;
            border-radius: 10px;
            padding: 10px 16px;
            cursor: pointer;
            font-weight: 600;
            letter-spacing: 0.01em;
        }
        button.secondary {
            background: #eef2f9;
            color: var(--text);
        }
        .notice {
            padding: 8px 12px;
            border-radius: 10px;
            margin-bottom: 12px;
            font-size: 13px;
        }
        .notice.error {
            background: #fff1f0;
            color: #8a1f0e;
            border: 1px solid #ffd3cd;
        }
        .notice.success {
            background: #ecf8f0;
            color: #225a34;
            border: 1px solid #bfe8c9;
        }
        .hint {
            font-size: 12px;
            color: var(--muted);
            margin-top: 6px;
        }
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            background: #eef2f9;
            color: #2e3b52;
            border-radius: 999px;
            padding: 6px 12px;
            border: 1px solid #d7deea;
        }
        .section {
            display: grid;
            gap: 12px;
        }
        .full {
            grid-column: 1 / -1;
        }
        .divider {
            height: 1px;
            background: var(--border);
            margin: 4px 0 0;
        }
        .field-group {
            display: grid;
            gap: 12px;
        }
        @media (max-width: 720px) {
            .wrap {
                padding: 20px 14px 48px;
            }
            .layout {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="hero">
        <span class="badge">LexNova Setup</span>
        <h1>Install LexNova</h1>
        <div class="pill">One-time setup</div>
    </div>

    <div class="layout">
        <div class="card">
            <h2>Status</h2>
            <?php foreach ($errors as $error): ?>
                <div class="notice error"><?php echo h($error); ?></div>
            <?php endforeach; ?>
            <?php foreach ($messages as $message): ?>
                <div class="notice success"><?php echo h($message); ?></div>
            <?php endforeach; ?>
            <?php if (!$errors && !$messages): ?>
                <div class="hint">
                    <?php echo $installerUnlocked ? 'Fill in the fields to continue.' : 'Enter the install password to unlock the installer.'; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$installerUnlocked): ?>
            <div class="card">
                <h2>Unlock Installer</h2>
                <?php if ($generatedPassword): ?>
                    <div class="notice success">Generated install password: <code><?php echo h($generatedPassword); ?></code></div>
                    <div class="hint">Copy it now. It is shown only once.</div>
                <?php elseif ($installReady): ?>
                    <div class="hint">If you lost the password, delete <code>install/install.pw</code> to generate a new one.</div>
                <?php endif; ?>
                <form method="post">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="unlock">
                    <div class="section">
                        <div>
                        <label for="install_pw">Install password</label>
                        <input id="install_pw" name="install_pw" type="password" required>
                        <div class="hint">Enter the generated password.</div>
                    </div>
                        <div class="actions">
                            <button type="submit">Unlock</button>
                        </div>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <div class="card">
                <h2>Install Details</h2>
                <form method="post">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="install">
                    <div class="section">
                    <div class="grid">
                        <div>
                            <label for="install_pw_confirm">Install password</label>
                            <input id="install_pw_confirm" name="install_pw" type="password" value="<?php echo h($installPwInput); ?>" required>
                            <div class="hint">Required to confirm installation.</div>
                        </div>
                        <div>
                            <label for="db_type">Database type</label>
                            <select id="db_type" name="db_type" required>
                                <option value="sqlite" <?php echo $dbType === 'sqlite' ? 'selected' : ''; ?>>SQLite</option>
                                <option value="mysql" <?php echo $dbType === 'mysql' ? 'selected' : ''; ?>>MySQL / MariaDB</option>
                                <option value="pgsql" <?php echo $dbType === 'pgsql' ? 'selected' : ''; ?>>PostgreSQL</option>
                            </select>
                        </div>
                        <div class="server-only">
                            <label for="db_host">Database host</label>
                            <input id="db_host" name="db_host" value="<?php echo h($dbHost); ?>" placeholder="localhost">
                        </div>
                        <div class="server-only">
                            <label for="db_port">Database port</label>
                            <input id="db_port" name="db_port" value="<?php echo h($dbPort); ?>" placeholder="Optional">
                        </div>
                        <div class="server-only">
                            <label for="db_name">Database name</label>
                            <input id="db_name" name="db_name" value="<?php echo h($dbName); ?>" placeholder="lexnova">
                        </div>
                        <div class="sqlite-only">
                            <label for="db_path">Database file (SQLite)</label>
                            <input id="db_path" name="db_path" value="<?php echo h($dbPath); ?>" placeholder="<?php echo h($defaultSqlitePath); ?>">
                        </div>
                        <div class="server-only">
                            <label for="db_user">Database user</label>
                            <input id="db_user" name="db_user" value="<?php echo h($dbUser); ?>">
                        </div>
                        <div class="server-only">
                            <label for="db_password">Database password</label>
                            <input id="db_password" name="db_password" type="password" value="<?php echo h($dbPassword); ?>">
                        </div>
                    </div>
                        <div class="divider"></div>
                        <div class="grid">
                            <div>
                                <label for="admin_username">Admin username</label>
                                <input id="admin_username" name="admin_username" value="<?php echo h($adminUsername); ?>" required>
                            </div>
                            <div>
                                <label for="admin_password">Admin password</label>
                                <input id="admin_password" name="admin_password" type="password" required>
                            </div>
                            <div>
                                <label for="admin_password_confirm">Confirm password</label>
                                <input id="admin_password_confirm" name="admin_password_confirm" type="password" required>
                            </div>
                        </div>
                        <div class="actions">
                            <button type="submit">Install LexNova</button>
                        </div>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>
<script>
    (function () {
        const dbType = document.getElementById('db_type');
        if (!dbType) {
            return;
        }
        const sqliteFields = document.querySelectorAll('.sqlite-only');
        const serverFields = document.querySelectorAll('.server-only');

        function toggleDbFields() {
            const isSqlite = dbType.value === 'sqlite';
            sqliteFields.forEach((field) => {
                field.style.display = isSqlite ? '' : 'none';
            });
            serverFields.forEach((field) => {
                field.style.display = isSqlite ? 'none' : '';
            });
        }

        dbType.addEventListener('change', toggleDbFields);
        toggleDbFields();
    })();
</script>
</body>
</html>
