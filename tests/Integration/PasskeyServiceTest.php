<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use LexNova\Service\PasskeyService;
use LexNova\Service\PasswordService;
use LexNova\Service\UserService;
use Symfony\Component\Uid\Uuid;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\TrustPath\EmptyTrustPath;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$db->executeStatement('CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username VARCHAR(255) NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    password_login_enabled INTEGER NOT NULL DEFAULT 1,
    role VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL
)');
$db->executeStatement('CREATE TABLE user_webauthn_credentials (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    credential_id VARCHAR(1024) NOT NULL UNIQUE,
    credential_data TEXT NOT NULL,
    label VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL,
    last_used_at DATETIME DEFAULT NULL
)');

$passwords = new PasswordService(['security' => [
    'password_policy' => ['min_length' => 8, 'max_length' => 256, 'min_score' => 0],
]]);
$users = new UserService($db, $passwords);
$userId = $users->create('fido-user', '', 'admin', false);
if ($users->verifyCredentials('fido-user', 'anything') !== null) {
    throw new RuntimeException('A Passkey-only account accepted a password.');
}

$passkeys = new PasskeyService($db, 'https://lexnova.example.test');
$registration = json_decode(
    $passkeys->createRegistrationOptions(['id' => $userId, 'username' => 'fido-user']),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (!is_array($registration) || !isset($registration['challenge'], $registration['user']['id'])) {
    throw new RuntimeException('WebAuthn registration options could not be serialized.');
}
$authentication = json_decode($passkeys->createAuthenticationOptions(), true, flags: JSON_THROW_ON_ERROR);
if (!is_array($authentication) || !isset($authentication['challenge'], $authentication['rpId'])) {
    throw new RuntimeException('WebAuthn authentication options could not be serialized.');
}

$credentialSource = new PublicKeyCredentialSource(
    'credential-id',
    'public-key',
    ['usb', 'nfc'],
    'none',
    EmptyTrustPath::create(),
    Uuid::fromString('fa2b99dc-9e39-4257-8f92-4a30d23c4118'),
    'public-key-data',
    'user-handle',
    0,
    otherUI: ['authenticator_attachment' => 'cross-platform'],
    backupEligible: false,
    backupStatus: false,
    uvInitialized: true,
);
$serializer = (new WebauthnSerializerFactory(new AttestationStatementSupportManager()))->create();
$db->insert('user_webauthn_credentials', [
    'user_id' => $userId,
    'credential_id' => 'test-credential',
    'credential_data' => $serializer->serialize($credentialSource, 'json'),
    'label' => 'Test key',
    'created_at' => '2026-08-14 00:00:00',
]);
$credentials = $passkeys->listForUser($userId);
if (count($credentials) !== 1
    || $credentials[0]['label'] !== 'Test key'
    || $credentials[0]['kind'] !== 'Externer FIDO2-Hardware-Key'
    || $credentials[0]['attachment'] !== 'cross-platform'
    || $credentials[0]['transports'] !== ['usb', 'nfc']
    || $credentials[0]['aaguid'] !== 'fa2b99dc-9e39-4257-8f92-4a30d23c4118'
    || !$users->hasPasskey($userId)
) {
    throw new RuntimeException('Passkey credential management is incomplete.');
}
if (!$passkeys->renameForUser((int) $credentials[0]['id'], $userId, 'Backup key')
    || $passkeys->listForUser($userId)[0]['label'] !== 'Backup key'
) {
    throw new RuntimeException('Passkey name could not be changed.');
}
if (!$passkeys->deleteForUser((int) $credentials[0]['id'], $userId) || $users->hasPasskey($userId)) {
    throw new RuntimeException('Passkey credential could not be deleted safely.');
}

echo "Passkey service integration test: OK\n";
