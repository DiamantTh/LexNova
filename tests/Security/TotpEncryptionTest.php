<?php

declare(strict_types=1);

use LexNova\Service\TotpService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (!function_exists('sodium_crypto_secretbox')) {
    echo "TOTP encryption security test: SKIPPED (Sodium capability unavailable)\n";

    exit(0);
}

$withoutKey = new TotpService('');

try {
    $withoutKey->encrypt('JBSWY3DPEHPK3PXP');
    throw new RuntimeException('TOTP encryption accepted a missing application key.');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'TOTP encryption key is missing or invalid.') {
        throw $error;
    }
}

if ($withoutKey->decrypt('JBSWY3DPEHPK3PXP') !== null) {
    throw new RuntimeException('TOTP decryption returned plaintext without an application key.');
}

$withKey = new TotpService(sodium_bin2hex(random_bytes(32)));
$secret = 'JBSWY3DPEHPK3PXP';
$encrypted = $withKey->encrypt($secret);

if ($encrypted === $secret || $withKey->decrypt($encrypted) !== $secret) {
    throw new RuntimeException('TOTP secret encryption round trip failed.');
}

echo "TOTP encryption security test: OK\n";
