<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use LexNova\Service\CredentialLimitService;
use LexNova\Service\PasskeyService;
use LexNova\Service\SystemSettingService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$db->executeStatement('CREATE TABLE system_settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT NOT NULL, updated_at DATETIME NOT NULL)');
$db->executeStatement('CREATE TABLE user_authenticators (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, kind VARCHAR(20) NOT NULL,
    credential_id VARCHAR(1024), credential_data TEXT, secret_enc TEXT, label VARCHAR(100) NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, last_used_at DATETIME DEFAULT NULL
)');
$settings = new SystemSettingService($db, new Psr16Cache(new ArrayAdapter()));
$limits = new CredentialLimitService($settings);

for ($index = 0; $index < 5; ++$index) {
    $db->insert('user_authenticators', [
        'user_id' => 7,
        'kind' => 'totp',
        'secret_enc' => 'ciphertext-' . $index,
        'label' => 'TOTP ' . $index,
        'created_at' => '2026-10-04 00:00:00',
    ]);
}
try {
    $limits->assertCanAdd('totp', 5);
    throw new RuntimeException('TOTP credential #6 was allowed at the default limit.');
} catch (RuntimeException $error) {
    if (!str_contains($error->getMessage(), 'limit')) {
        throw $error;
    }
}
$limits->assertCanAdd('totp', 4);

for ($index = 0; $index < 11; ++$index) {
    $db->insert('user_authenticators', [
        'user_id' => 8,
        'kind' => 'webauthn',
        'credential_id' => 'credential-' . $index,
        'credential_data' => 'serialized-' . $index,
        'label' => 'Passkey ' . $index,
        'created_at' => '2026-10-04 00:00:00',
    ]);
}
try {
    $limits->assertCanAdd('webauthn', 10);
    throw new RuntimeException('WebAuthn credential #11 was allowed at the default limit.');
} catch (RuntimeException $error) {
    if (!str_contains($error->getMessage(), 'limit')) {
        throw $error;
    }
}

$settings->setInt('auth.limit.webauthn', 5);
try {
    $limits->assertCanAdd('webauthn', 11);
    throw new RuntimeException('A lowered limit did not block new registration.');
} catch (RuntimeException $error) {
    if (!str_contains($error->getMessage(), 'limit')) {
        throw $error;
    }
}
if ((int) $db->fetchOne("SELECT COUNT(*) FROM user_authenticators WHERE user_id = 8 AND kind = 'webauthn'") !== 11) {
    throw new RuntimeException('Lowering a credential limit deleted stored credentials.');
}
$passkeys = new PasskeyService($db, 'https://lexnova.example.test', credentialLimits: $limits);
try {
    $passkeys->createRegistrationOptions(['id' => 8, 'username' => 'many-keys']);
    throw new RuntimeException('PasskeyService allowed enrollment above the lowered WebAuthn limit.');
} catch (RuntimeException $error) {
    if (!str_contains($error->getMessage(), 'limit')) {
        throw $error;
    }
}
try {
    $settings->setInt('auth.limit.totp', 101);
    throw new RuntimeException('The credential limit hard cap was not enforced.');
} catch (InvalidArgumentException) {
}

echo "Credential limit security test: OK\n";
