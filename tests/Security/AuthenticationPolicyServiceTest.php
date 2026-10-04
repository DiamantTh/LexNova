<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use LexNova\Service\AuthenticationPolicyService;
use LexNova\Service\PasswordService;
use LexNova\Service\StepUpGrant;
use LexNova\Service\TotpService;
use LexNova\Service\UserService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$db->executeStatement('CREATE TABLE users (
    id INTEGER PRIMARY KEY, username TEXT NOT NULL, password_hash TEXT NOT NULL,
    password_login_enabled INTEGER NOT NULL, mfa_required INTEGER NOT NULL,
    activation_required INTEGER NOT NULL DEFAULT 0, role TEXT NOT NULL, created_at DATETIME NOT NULL
)');
$db->executeStatement('CREATE TABLE user_authenticators (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, kind TEXT NOT NULL,
    credential_id TEXT UNIQUE, credential_data TEXT, secret_enc TEXT, label TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, last_used_at DATETIME NULL
)');
$totp = new TotpService(sodium_bin2hex(random_bytes(32)));
$users = new UserService($db, new PasswordService(['security' => [
    'password_policy' => ['min_length' => 8, 'max_length' => 256, 'min_score' => 0],
]]));
$policy = new AuthenticationPolicyService($users, $totp);

$insertUser = static function (int $id, bool $password = false, bool $mfa = true) use ($db): void {
    $db->insert('users', [
        'id' => $id,
        'username' => 'user-' . $id,
        'password_hash' => 'unused-test-hash',
        'password_login_enabled' => $password ? 1 : 0,
        'mfa_required' => $mfa ? 1 : 0,
        'activation_required' => 0,
        'role' => 'admin',
        'created_at' => '2026-10-04 00:00:00',
    ]);
};
$addPasskey = static function (int $userId) use ($db): int {
    $db->insert('user_authenticators', [
        'user_id' => $userId,
        'kind' => 'webauthn',
        'credential_id' => 'credential-' . bin2hex(random_bytes(8)),
        'credential_data' => 'source',
        'label' => 'FIDO2',
        'is_active' => 1,
        'created_at' => '2026-10-04 00:00:00',
    ]);

    return (int) $db->lastInsertId();
};
$addTotp = static function (int $userId, string $secret) use ($db, $totp): int {
    $db->insert('user_authenticators', [
        'user_id' => $userId,
        'kind' => 'totp',
        'secret_enc' => $totp->encrypt($secret),
        'label' => 'TOTP',
        'is_active' => 1,
        'created_at' => '2026-10-04 00:00:00',
    ]);

    return (int) $db->lastInsertId();
};
$grant = static fn (int $userId, string $method, int $authenticatorId, string $action, string $target): StepUpGrant => new StepUpGrant(
    $userId,
    $action,
    $target,
    $method,
    $authenticatorId,
);

// FIDO2: 3 -> 2 allows any enrolled key, including the target.
$insertUser(10);
$fidoThree = [$addPasskey(10), $addPasskey(10), $addPasskey(10)];
$fidoTarget = $fidoThree[0];
$fidoDecision = $policy->credentialRemovalPolicy(10, 'webauthn', $fidoTarget);
if (($fidoDecision['allowed'] ?? false) !== true
    || $policy->hasValidAuthenticationPathAfterRemoval(10, 'webauthn', $fidoTarget) !== true
    || $policy->acceptsRemovalProof(
        $fidoDecision,
        $grant(10, 'totp', 99, 'auth.webauthn.delete', 'user:10/passkey:' . $fidoTarget),
        $fidoTarget,
    )
    || !$policy->acceptsRemovalProof(
        $fidoDecision,
        $grant(10, 'webauthn', $fidoTarget, 'auth.webauthn.delete', 'user:10/passkey:' . $fidoTarget),
        $fidoTarget,
    )
) {
    throw new RuntimeException('FIDO2 removal from 3 to 2 did not allow any enrolled key.');
}

// FIDO2: 2 -> 1 must exclude and reject the credential being removed.
$insertUser(11);
$fidoTwo = [$addPasskey(11), $addPasskey(11)];
$fidoDecision = $policy->credentialRemovalPolicy(11, 'webauthn', $fidoTwo[0]);
if (($fidoDecision['warning'] ?? null) === null
    || ($fidoDecision['excluded_authenticator_id'] ?? null) !== $fidoTwo[0]
    || !$policy->acceptsRemovalProof(
        $fidoDecision,
        $grant(11, 'webauthn', $fidoTwo[1], 'auth.webauthn.delete', 'user:11/passkey:' . $fidoTwo[0]),
        $fidoTwo[0],
    )
    || $policy->acceptsRemovalProof(
        $fidoDecision,
        $grant(11, 'webauthn', $fidoTwo[0], 'auth.webauthn.delete', 'user:11/passkey:' . $fidoTwo[0]),
        $fidoTwo[0],
    )
) {
    throw new RuntimeException('FIDO2 removal from 2 to 1 did not require the other key.');
}

// FIDO2: 1 -> 0 is forbidden for self-service, even with password or TOTP.
$insertUser(12, true);
$fidoOne = $addPasskey(12);
if (($policy->credentialRemovalPolicy(12, 'webauthn', $fidoOne)['allowed'] ?? true) !== false) {
    throw new RuntimeException('The last FIDO2 credential was allowed to be removed in self-service.');
}

// TOTP: 3 -> 2 allows the target TOTP or a stronger FIDO2 authenticator.
$insertUser(13);
$totpThree = [$addTotp(13, 'JBSWY3DPEHPK3PXP'), $addTotp(13, 'KRUGS4ZANFZSAYJA'), $addTotp(13, 'MFRGGZDFMZTWQ2LK')];
$totpFido = $addPasskey(13);
$totpDecision = $policy->credentialRemovalPolicy(13, 'totp', $totpThree[0]);
if (($totpDecision['allowed'] ?? false) !== true
    || !$policy->acceptsRemovalProof($totpDecision, $grant(13, 'totp', $totpThree[0], 'auth.totp.delete', 'user:13/totp:' . $totpThree[0]), $totpThree[0])
    || !$policy->acceptsRemovalProof($totpDecision, $grant(13, 'webauthn', $totpFido, 'auth.totp.delete', 'user:13/totp:' . $totpThree[0]), $totpThree[0])
) {
    throw new RuntimeException('TOTP removal from 3 to 2 did not allow either TOTP or FIDO2 proof.');
}

// TOTP: 2 -> 1 requires another TOTP if TOTP is used; FIDO2 remains an alternative.
$insertUser(14);
$totpTwo = [$addTotp(14, 'JBSWY3DPEHPK3PXP'), $addTotp(14, 'KRUGS4ZANFZSAYJA')];
$totpFido = $addPasskey(14);
$totpDecision = $policy->credentialRemovalPolicy(14, 'totp', $totpTwo[0]);
if (($totpDecision['warning'] ?? null) === null
    || !$policy->acceptsRemovalProof($totpDecision, $grant(14, 'totp', $totpTwo[1], 'auth.totp.delete', 'user:14/totp:' . $totpTwo[0]), $totpTwo[0])
    || $policy->acceptsRemovalProof($totpDecision, $grant(14, 'totp', $totpTwo[0], 'auth.totp.delete', 'user:14/totp:' . $totpTwo[0]), $totpTwo[0])
    || !$policy->acceptsRemovalProof($totpDecision, $grant(14, 'webauthn', $totpFido, 'auth.totp.delete', 'user:14/totp:' . $totpTwo[0]), $totpTwo[0])
) {
    throw new RuntimeException('TOTP removal from 2 to 1 did not require a different TOTP or allow FIDO2.');
}

// TOTP: 1 -> 0 requires an existing FIDO2 credential.
$insertUser(15);
$lastTotp = $addTotp(15, 'JBSWY3DPEHPK3PXP');
$lastTotpFido = $addPasskey(15);
$totpDecision = $policy->credentialRemovalPolicy(15, 'totp', $lastTotp);
if (($totpDecision['allowed_methods'] ?? []) !== ['webauthn']
    || !$policy->acceptsRemovalProof($totpDecision, $grant(15, 'webauthn', $lastTotpFido, 'auth.totp.delete', 'user:15/totp:' . $lastTotp), $lastTotp)
    || $policy->acceptsRemovalProof($totpDecision, $grant(15, 'totp', $lastTotp, 'auth.totp.delete', 'user:15/totp:' . $lastTotp), $lastTotp)
) {
    throw new RuntimeException('The last TOTP credential was not restricted to FIDO2 proof.');
}
$insertUser(16);
$lastTotp = $addTotp(16, 'JBSWY3DPEHPK3PXP');
if (($policy->credentialRemovalPolicy(16, 'totp', $lastTotp)['allowed'] ?? true) !== false) {
    throw new RuntimeException('The last TOTP credential was allowed without FIDO2.');
}

// Identical TOTP secrets remain one logical authenticator after row removal.
$insertUser(17, true);
$sameTotp = $addTotp(17, 'JBSWY3DPEHPK3PXP');
$addTotp(17, 'JBSWY3DPEHPK3PXP');
if ($totp->uniqueCredentialCount($users->getStoredTotpCredentials(17, true)) !== 1
    || !$policy->hasValidAuthenticationPathAfterRemoval(17, 'totp', $sameTotp)
) {
    throw new RuntimeException('Duplicate TOTP secret rows were counted as independent credentials.');
}

// General lockout reachability stays separate from concrete removal decisions.
$insertUser(18, true, false);
$passwordOnlyFido = $addPasskey(18);
if (!$policy->hasValidAuthenticationPathAfterRemoval(18, 'webauthn', $passwordOnlyFido)
    || !$policy->canDisablePasswordLogin(18)
) {
    throw new RuntimeException('General authentication-path policy no longer matches the account state.');
}

echo "Authentication policy security test: OK\n";
