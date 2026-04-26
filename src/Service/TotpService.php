<?php

declare(strict_types=1);

namespace LexNova\Service;

use OTPHP\TOTP;

/**
 * Handles TOTP (RFC 6238) two-factor authentication.
 *
 * Configured with SHA-256 + 8 digits — stronger than the RFC default
 * (SHA-1, 6 digits). Not compatible with Google Authenticator.
 * Recommended apps: Aegis, 2FAS, Raivo, KeePassXC, Ente Auth, Bitwarden Auth.
 *
 * TOTP secrets are encrypted at rest using libsodium (XSalsa20-Poly1305)
 * with an application key generated at install time and stored in configs/config.toml.
 */
final readonly class TotpService
{
    public function __construct(
        /** Hex-encoded 32-byte sodium key. Empty string = no encryption (pre-install). */
        private readonly string $appKey,
        private readonly int $digits = 8,
        private readonly string $algorithm = 'sha256',
        private readonly int $period = 30,
        private readonly int $window = 1,
    ) {
    }

    /**
     * Generates a new TOTP with a random secret.
     *
     * @return array{secret: string, encrypted: string, uri: string}
     */
    public function generate(string $issuer, string $account): array
    {
        $totp = TOTP::generate(
            digest: $this->algorithm,
            digits: $this->digits,
            period: $this->period,
        );
        $totp->setLabel($account);
        $totp->setIssuer($issuer);

        $secret = $totp->getSecret();

        return [
            'secret' => $secret,
            'encrypted' => $this->encrypt($secret),
            'uri' => $totp->getProvisioningUri(),
        ];
    }

    /**
     * Returns the provisioning URI for a known Base32 secret (e.g. during
     * enrollment when the secret is stored in the session but not yet saved).
     */
    public function getProvisioningUri(string $secret, string $issuer, string $account): string
    {
        $totp = TOTP::createFromSecret($secret);
        $totp->setDigits($this->digits);
        $totp->setDigest($this->algorithm);
        $totp->setPeriod($this->period);
        $totp->setLabel($account);
        $totp->setIssuer($issuer);

        return $totp->getProvisioningUri();
    }

    /**
     * Verifies a code against an encrypted TOTP secret (post-enrollment, login step).
     */
    public function verify(string $encryptedSecret, string $code): bool
    {
        $secret = $this->decrypt($encryptedSecret);
        if ($secret === null) {
            return false;
        }

        return $this->verifyPlain($secret, $code);
    }

    /**
     * Verifies a code against multiple active keys (multi-key support).
     * Returns the matching key's ID, or null if no key matches.
     *
     * @param list<array{id: int, secret_enc: string}> $keys
     */
    public function verifyAny(array $keys, string $code): ?int
    {
        foreach ($keys as $key) {
            if ($this->verify((string) $key['secret_enc'], $code)) {
                return (int) $key['id'];
            }
        }

        return null;
    }

    /**
     * Verifies a code against a plain Base32 secret (during enrollment before DB save).
     */
    public function verifyPlain(string $secret, string $code): bool
    {
        $totp = TOTP::createFromSecret($secret);
        $totp->setDigits($this->digits);
        $totp->setDigest($this->algorithm);
        $totp->setPeriod($this->period);

        // leeway in seconds: window * period allows for clock drift
        return $totp->verify($code, null, $this->window * $this->period);
    }

    /**
     * Encrypts a Base32 TOTP secret using XSalsa20-Poly1305 (libsodium).
     * Falls back to plain text when no app key is available (pre-install).
     */
    public function encrypt(string $secret): string
    {
        if (!$this->hasKey()) {
            return $secret;
        }

        $key = sodium_hex2bin($this->appKey);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox($secret, $nonce, $key);
        sodium_memzero($key);

        return sodium_bin2hex($nonce . $box);
    }

    /**
     * Decrypts an encrypted TOTP secret. Returns null on failure.
     */
    public function decrypt(string $stored): ?string
    {
        if (!$this->hasKey()) {
            return $stored; // no-op encryption fallback
        }

        try {
            $raw = sodium_hex2bin($stored);
            $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $box = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $key = sodium_hex2bin($this->appKey);

            $plain = sodium_crypto_secretbox_open($box, $nonce, $key);
            sodium_memzero($key);

            return $plain !== false ? $plain : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** A valid key is 32 bytes = 64 hex chars. */
    private function hasKey(): bool
    {
        return strlen($this->appKey) === 64;
    }
}
