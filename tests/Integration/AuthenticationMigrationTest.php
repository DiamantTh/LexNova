<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT NOT NULL, password_hash TEXT NOT NULL, password_login_enabled INTEGER NOT NULL DEFAULT 1, role TEXT NOT NULL, created_at DATETIME NOT NULL)');
$pdo->exec('CREATE TABLE user_webauthn_credentials (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, credential_id TEXT NOT NULL UNIQUE, credential_data TEXT NOT NULL, label TEXT NOT NULL, created_at DATETIME NOT NULL, last_used_at DATETIME NULL)');
$pdo->exec('CREATE TABLE user_totp_keys (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, secret_enc TEXT NOT NULL, label TEXT NOT NULL, is_active INTEGER NOT NULL, created_at DATETIME NOT NULL, last_used_at DATETIME NULL)');
$pdo->exec('CREATE TABLE login_attempts (id INTEGER PRIMARY KEY, ip TEXT NOT NULL, endpoint TEXT NOT NULL, attempts INTEGER NOT NULL, blocked_until DATETIME NULL, last_at DATETIME NOT NULL)');
$pdo->exec('CREATE TABLE audit_log (id INTEGER PRIMARY KEY, actor_id INTEGER NULL, actor_name TEXT NULL, action TEXT NOT NULL, target TEXT NULL, detail TEXT NULL, ip TEXT NULL, created_at DATETIME NOT NULL)');
$pdo->exec("INSERT INTO users (id, username, password_hash, role, created_at) VALUES (4, 'migration-user', 'password-hash', 'admin', '2026-10-04 00:00:00')");
$pdo->exec("INSERT INTO user_webauthn_credentials VALUES (7, 4, 'credential-id-base64url', '{\"serialized\":\"credential-source\"}', 'Security key', '2025-01-02 03:04:05', '2025-03-04 05:06:07')");
$pdo->exec("INSERT INTO user_totp_keys VALUES (8, 4, 'encrypted-totp-fixture', 'Phone authenticator', 1, '2025-02-03 04:05:06', '2025-04-05 06:07:08')");
$pdo->exec("INSERT INTO login_attempts VALUES (9, '192.0.2.9', 'passkey', 3, NULL, '2026-10-04 01:00:00')");
$pdo->exec("INSERT INTO audit_log VALUES (10, 4, 'migration-user', 'auth.login', 'user:4', NULL, '192.0.2.9', '2026-10-04 01:01:00')");

$migration = file_get_contents(dirname(__DIR__, 2) . '/sql/migrations/006_shared_authentication.sql');
if (!is_string($migration)) {
    throw new RuntimeException('Authentication migration could not be read.');
}
$pdo->exec($migration);

$passkey = $pdo->query("SELECT user_id, credential_id, credential_data, label, created_at, last_used_at FROM user_authenticators WHERE kind = 'webauthn'")->fetch(PDO::FETCH_ASSOC);
$totp = $pdo->query("SELECT user_id, secret_enc, label, is_active, created_at, last_used_at FROM user_authenticators WHERE kind = 'totp'")->fetch(PDO::FETCH_ASSOC);
if ($passkey !== [
    'user_id' => 4,
    'credential_id' => 'credential-id-base64url',
    'credential_data' => '{"serialized":"credential-source"}',
    'label' => 'Security key',
    'created_at' => '2025-01-02 03:04:05',
    'last_used_at' => '2025-03-04 05:06:07',
]) {
    throw new RuntimeException('WebAuthn migration did not preserve the credential and metadata.');
}
if ($totp !== [
    'user_id' => 4,
    'secret_enc' => 'encrypted-totp-fixture',
    'label' => 'Phone authenticator',
    'is_active' => 1,
    'created_at' => '2025-02-03 04:05:06',
    'last_used_at' => '2025-04-05 06:07:08',
]) {
    throw new RuntimeException('TOTP migration did not preserve its ciphertext and metadata.');
}
if ((int) $pdo->query('SELECT mfa_required FROM users WHERE id = 4')->fetchColumn() !== 1
    || (int) $pdo->query("SELECT COUNT(*) FROM rate_limit_buckets WHERE ip = '192.0.2.9'")->fetchColumn() !== 1
    || (int) $pdo->query("SELECT COUNT(*) FROM audit_events WHERE actor_user_id = 4")->fetchColumn() !== 1
) {
    throw new RuntimeException('Authentication policy, rate-limit, or audit data was not migrated.');
}

echo "Authentication migration preservation test: OK\n";
