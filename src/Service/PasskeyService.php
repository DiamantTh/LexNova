<?php

declare(strict_types=1);

namespace LexNova\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Server-side WebAuthn ceremonies and credential persistence.
 *
 * Challenges are intentionally kept in the session by the HTTP handlers. This
 * service only accepts the original serialized options, so callers can make a
 * challenge single-use before invoking cryptographic validation.
 */
final readonly class PasskeyService
{
    /** @var array{scheme: string, host: string, port?: int}|null */
    private ?array $baseUrl;

    private SerializerInterface $serializer;

    public function __construct(
        private Connection $db,
        string $baseUrl,
        private string $rpName = 'LexNova',
        private ?string $configuredRpId = null,
        private ?string $configuredOrigin = null,
        private int $configuredLimit = 10,
        private ?AuditService $audit = null,
        private ?CredentialLimitService $credentialLimits = null,
    ) {
        $this->baseUrl = $this->parseBaseUrl($baseUrl);
        $this->serializer = (new WebauthnSerializerFactory(new AttestationStatementSupportManager()))->create();
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== null;
    }

    /** @param array{id: int, username: string} $user */
    public function createRegistrationOptions(array $user, bool $preferCrossPlatform = false): string
    {
        $this->assertConfigured();
        $this->assertRpPolicyStable();
        $count = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM user_authenticators WHERE user_id = ? AND kind = 'webauthn'",
            [$user['id']],
        );
        if ($this->credentialLimits !== null) {
            $this->credentialLimits->assertCanAdd('webauthn', $count);
        } elseif ($count >= max(1, min(100, $this->configuredLimit))) {
            throw new \RuntimeException('The WebAuthn credential limit has been reached. Remove a credential before adding another.');
        }
        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create($this->rpName, $this->rpId()),
            PublicKeyCredentialUserEntity::create(
                $user['username'],
                $this->userHandle($user['id']),
                $user['username'],
            ),
            random_bytes(32),
            [
                PublicKeyCredentialParameters::createPk(-7),   // ES256
                PublicKeyCredentialParameters::createPk(-257), // RS256
            ],
            AuthenticatorSelectionCriteria::create(
                authenticatorAttachment: $preferCrossPlatform ? AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_CROSS_PLATFORM : null,
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            $this->credentialDescriptorsForUser($user['id']),
            120000,
        );

        return $this->serializer->serialize($options, 'json');
    }

    /** @param array{id: int, username: string} $user */
    public function createAuthenticationOptions(array $user): string
    {
        $this->assertConfigured();
        $this->assertRpPolicyStable();
        $options = PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            $this->rpId(),
            $this->credentialDescriptorsForUser($user['id']),
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            120000,
        );

        return $this->serializer->serialize($options, 'json');
    }

    public function finishRegistration(
        int $userId,
        string $optionsJson,
        string $credentialJson,
        string $label,
        ?string $authenticatorAttachment = null,
    ): int {
        $this->assertConfigured();
        $this->assertRpPolicyStable();
        $options = $this->serializer->deserialize($optionsJson, PublicKeyCredentialCreationOptions::class, 'json');
        $credential = $this->serializer->deserialize($credentialJson, PublicKeyCredential::class, 'json');
        if (!$credential->response instanceof AuthenticatorAttestationResponse) {
            throw new \RuntimeException('Invalid passkey registration response.');
        }

        $source = $this->attestationValidator()->check($credential->response, $options, $this->rpId());
        if (!hash_equals($this->userHandle($userId), $source->userHandle)) {
            throw new \RuntimeException('Passkey does not belong to the current user.');
        }
        if (in_array($authenticatorAttachment, ['platform', 'cross-platform'], true)) {
            $source->otherUI = array_replace(
                $source->otherUI ?? [],
                ['authenticator_attachment' => $authenticatorAttachment],
            );
        }

        // Recheck after the ceremony: several enrollment tabs may have been
        // opened while the account was still below its configured limit.
        $currentCount = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM user_authenticators WHERE user_id = ? AND kind = 'webauthn'",
            [$userId],
        );
        if ($this->credentialLimits !== null) {
            $this->credentialLimits->assertCanAdd('webauthn', $currentCount);
        } elseif ($currentCount >= max(1, min(100, $this->configuredLimit))) {
            throw new \RuntimeException('The WebAuthn credential limit has been reached. Remove a credential before adding another.');
        }

        $this->db->insert('user_authenticators', [
            'user_id' => $userId,
            'kind' => 'webauthn',
            'credential_id' => $this->encodeId($source->publicKeyCredentialId),
            'credential_data' => $this->serializer->serialize($source, 'json'),
            'label' => $this->normaliseLabel($label),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->db->update('users', ['mfa_required' => true], ['id' => $userId]);
        $this->pinRpPolicy();

        return (int) $this->db->lastInsertId();
    }

    /** @return array{id: int, username: string, role: string} */
    public function finishAuthentication(string $optionsJson, string $credentialJson, int $expectedUserId): array
    {
        $this->assertConfigured();
        $this->assertRpPolicyStable();
        $options = $this->serializer->deserialize($optionsJson, PublicKeyCredentialRequestOptions::class, 'json');
        $credential = $this->serializer->deserialize($credentialJson, PublicKeyCredential::class, 'json');
        if (!$credential->response instanceof AuthenticatorAssertionResponse) {
            throw new \RuntimeException('Invalid passkey authentication response.');
        }

        $row = $this->findCredential($credential->rawId, $expectedUserId);
        if ($row === null) {
            throw new \RuntimeException('Unknown passkey.');
        }

        $source = $this->serializer->deserialize((string) $row['credential_data'], PublicKeyCredentialSource::class, 'json');
        $source = $this->assertionValidator()->check(
            $source,
            $credential->response,
            $options,
            $this->rpId(),
            $source->userHandle,
        );

        $this->db->update('user_authenticators', [
            'credential_data' => $this->serializer->serialize($source, 'json'),
            'last_used_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $row['authenticator_id'], 'kind' => 'webauthn', 'user_id' => $expectedUserId]);

        return [
            'id' => (int) $row['user_id'],
            'username' => (string) $row['username'],
            'role' => (string) $row['role'],
        ];
    }

    /**
     * @return list<array{
     *   id: mixed, label: mixed, created_at: mixed, last_used_at: mixed,
     *   kind: string, attachment: ?string, transports: list<string>, aaguid: ?string,
     *   manufacturer: ?string, backup_eligible: ?bool, backup_status: ?bool
     * }>
     */
    public function listForUser(int $userId): array
    {
        $rows = $this->db->createQueryBuilder()
            ->select('id', 'label', 'created_at', 'last_used_at', 'credential_data')
            ->from('user_authenticators')
            ->where('user_id = :user_id AND kind = :kind')
            ->setParameter('user_id', $userId)
            ->setParameter('kind', 'webauthn')
            ->orderBy('id', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(function (array $row): array {
            $details = $this->credentialDetails((string) $row['credential_data']);
            unset($row['credential_data']);

            return array_merge($row, $details);
        }, $rows);
    }

    public function renameForUser(int $credentialId, int $userId, string $label): bool
    {
        return $this->db->update(
            'user_authenticators',
            ['label' => $this->normaliseLabel($label)],
            ['id' => $credentialId, 'user_id' => $userId, 'kind' => 'webauthn'],
        ) > 0;
    }

    public function deleteForUser(int $credentialId, int $userId): bool
    {
        return $this->db->delete('user_authenticators', [
            'id' => $credentialId,
            'user_id' => $userId,
            'kind' => 'webauthn',
        ]) > 0;
    }

    /**
     * Manufacturer names are intentionally not guessed from transports or the
     * AAGUID. A reliable name needs trusted FIDO metadata; attestation "none"
     * can also deliberately suppress identifying information.
     *
     * @return array{
     *   kind: string, attachment: ?string, transports: list<string>, aaguid: ?string,
     *   manufacturer: ?string, backup_eligible: ?bool, backup_status: ?bool
     * }
     */
    private function credentialDetails(string $credentialData): array
    {
        $fallback = [
            'kind' => 'Passkey (Art nicht gemeldet)',
            'attachment' => null,
            'transports' => [],
            'aaguid' => null,
            'manufacturer' => null,
            'backup_eligible' => null,
            'backup_status' => null,
        ];

        try {
            $source = $this->serializer->deserialize($credentialData, PublicKeyCredentialSource::class, 'json');
            $transports = array_values($source->transports);
            $aaguid = $source->aaguid->toRfc4122();
            if ($aaguid === '00000000-0000-0000-0000-000000000000') {
                $aaguid = null;
            }
            $attachment = $source->otherUI['authenticator_attachment'] ?? null;
            if (!in_array($attachment, ['platform', 'cross-platform'], true)) {
                $attachment = null;
            }
            $kind = match (true) {
                $attachment === 'cross-platform' => 'Cross-platform Authenticator (Geräteart nicht verifiziert)',
                in_array('hybrid', $transports, true) => 'Hybrid-Transport gemeldet',
                array_intersect(['usb', 'nfc', 'ble'], $transports) !== [] => 'Transport gemeldet (kein Herstellernachweis)',
                $attachment === 'platform' && $source->backupEligible === true => 'Platform-Authenticator (Backup Eligible)',
                $attachment === 'platform', in_array('internal', $transports, true) => 'Platform-Authenticator gemeldet',
                $source->backupEligible === true => 'Backup Eligible',
                default => 'Passkey (Art nicht gemeldet)',
            };

            return [
                'kind' => $kind,
                'attachment' => $attachment,
                'transports' => $transports,
                'aaguid' => $aaguid,
                'manufacturer' => null,
                'backup_eligible' => $source->backupEligible,
                'backup_status' => $source->backupStatus,
            ];
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /** @return list<\Webauthn\PublicKeyCredentialDescriptor> */
    private function credentialDescriptorsForUser(int $userId): array
    {
        $rows = $this->db->createQueryBuilder()
            ->select('credential_data')
            ->from('user_authenticators')
            ->where('user_id = :user_id AND kind = :kind')
            ->setParameter('user_id', $userId)
            ->setParameter('kind', 'webauthn')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map(
            fn (string $data) => $this->serializer
                ->deserialize($data, PublicKeyCredentialSource::class, 'json')
                ->getPublicKeyCredentialDescriptor(),
            $rows,
        );
    }

    /** @return array<string, mixed>|null */
    private function findCredential(string $rawId, int $expectedUserId): ?array
    {
        $row = $this->db->createQueryBuilder()
            ->select('c.id AS authenticator_id', 'c.user_id', 'c.credential_data', 'u.username', 'u.role')
            ->from('user_authenticators', 'c')
            ->join('c', 'users', 'u', 'c.user_id = u.id')
            ->where('c.credential_id = :credential_id AND c.user_id = :user_id AND c.kind = :kind')
            ->setParameter('credential_id', $this->encodeId($rawId))
            ->setParameter('user_id', $expectedUserId)
            ->setParameter('kind', 'webauthn')
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }

    private function attestationValidator(): AuthenticatorAttestationResponseValidator
    {
        return AuthenticatorAttestationResponseValidator::create($this->ceremonyFactory()->creationCeremony());
    }

    private function assertionValidator(): AuthenticatorAssertionResponseValidator
    {
        return AuthenticatorAssertionResponseValidator::create($this->ceremonyFactory()->requestCeremony());
    }

    private function ceremonyFactory(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        $factory->setAllowedOrigins([$this->origin()]);

        return $factory;
    }

    private function origin(): string
    {
        $this->assertConfigured();
        $origin = $this->configuredOrigin;
        if ($origin !== null) {
            $parsed = parse_url($origin);
            if (!is_array($parsed) || !isset($parsed['scheme'], $parsed['host'])
                || $parsed['scheme'] !== $this->baseUrl['scheme']
                || strtolower($parsed['host']) !== $this->baseUrl['host']
                || array_intersect(['user', 'pass', 'query', 'fragment', 'path'], array_keys($parsed)) !== []
            ) {
                throw new \RuntimeException('Configured WebAuthn origin does not match app.base_url.');
            }
            $configuredPort = isset($parsed['port']) ? (int) $parsed['port'] : null;
            $basePort = $this->baseUrl['port'] ?? null;
            $configuredPort = $configuredPort === ($parsed['scheme'] === 'https' ? 443 : 80) ? null : $configuredPort;
            $basePort = $basePort === ($this->baseUrl['scheme'] === 'https' ? 443 : 80) ? null : $basePort;
            if ($configuredPort !== $basePort) {
                throw new \RuntimeException('Configured WebAuthn origin port does not match app.base_url.');
            }

            return rtrim($origin, '/');
        }
        $port = $this->baseUrl['port'] ?? null;
        $isDefaultPort = ($this->baseUrl['scheme'] === 'https' && $port === 443)
            || ($this->baseUrl['scheme'] === 'http' && $port === 80);

        return $this->baseUrl['scheme'] . '://' . $this->baseUrl['host']
            . ($port !== null && !$isDefaultPort ? ':' . $port : '');
    }

    private function rpId(): string
    {
        $this->assertConfigured();

        $rpId = strtolower(trim((string) $this->configuredRpId));
        if ($rpId === '') {
            return $this->baseUrl['host'];
        }
        $host = $this->baseUrl['host'];
        if ($host !== $rpId && !str_ends_with($host, '.' . $rpId)) {
            throw new \RuntimeException('Configured WebAuthn RP ID is not a parent of app.base_url.');
        }

        return $rpId;
    }

    private function assertConfigured(): void
    {
        if ($this->baseUrl === null) {
            throw new \RuntimeException('Passkeys require a valid app.base_url configuration.');
        }
    }

    private function assertRpPolicyStable(): void
    {
        if ($this->db->fetchOne("SELECT id FROM user_authenticators WHERE kind = 'webauthn' LIMIT 1") === false) {
            $storedRpId = $this->storedSetting('auth.webauthn.rp_id');
            $storedOrigin = $this->storedSetting('auth.webauthn.origin');
            $rpId = $this->rpId();
            $origin = $this->origin();
            if (($storedRpId !== null && !hash_equals($storedRpId, $rpId))
                || ($storedOrigin !== null && !hash_equals($storedOrigin, $origin))
            ) {
                $this->audit?->log(
                    null,
                    null,
                    'auth.webauthn_config_changed',
                    'instance:webauthn',
                    'RP ID or Origin changed while no credentials existed; current validated values were pinned.',
                );
            }
            $this->pinRpPolicy(replace: true);

            return;
        }

        $storedRpId = $this->storedSetting('auth.webauthn.rp_id');
        $storedOrigin = $this->storedSetting('auth.webauthn.origin');
        if ($storedRpId !== null && !hash_equals($storedRpId, $this->rpId())) {
            $this->audit?->log(null, null, 'auth.webauthn_config_changed', 'instance:webauthn', 'configured RP ID differs from pinned RP ID');
            throw new \RuntimeException('WebAuthn RP ID changed while credentials exist; explicit migration or recovery is required.');
        }
        if ($storedOrigin !== null && !hash_equals($storedOrigin, $this->origin())) {
            $this->audit?->log(null, null, 'auth.webauthn_config_changed', 'instance:webauthn', 'configured Origin differs from pinned Origin');
            throw new \RuntimeException('WebAuthn origin changed while credentials exist; explicit migration or recovery is required.');
        }

        // Legacy databases did not pin these values. Pin the first value seen so
        // future configuration edits cannot silently invalidate enrolled keys.
        if ($storedRpId === null || $storedOrigin === null) {
            $this->pinRpPolicy();
            $this->audit?->log(
                null,
                null,
                'auth.webauthn_legacy_policy_pinned',
                'instance:webauthn',
                'Legacy credentials existed without stored RP ID or Origin; the current validated configuration was pinned.',
            );
        }
    }

    private function pinRpPolicy(bool $replace = false): void
    {
        foreach ([
            'auth.webauthn.rp_id' => $this->rpId(),
            'auth.webauthn.origin' => $this->origin(),
        ] as $key => $value) {
            $existing = $this->storedSetting($key);
            if ($existing !== null && $replace) {
                $this->db->update('system_settings', [
                    'setting_value' => $value,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ], ['setting_key' => $key]);
            } elseif ($existing === null) {
                $this->db->insert('system_settings', [
                    'setting_key' => $key,
                    'setting_value' => $value,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    private function storedSetting(string $key): ?string
    {
        try {
            $value = $this->db->fetchOne('SELECT setting_value FROM system_settings WHERE setting_key = ?', [$key]);
        } catch (\Throwable) {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    /** @return array{scheme: string, host: string, port?: int}|null */
    private function parseBaseUrl(string $baseUrl): ?array
    {
        $parsed = parse_url($baseUrl);
        if (!is_array($parsed) || !isset($parsed['scheme'], $parsed['host'])
            || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parsed)) !== []
            || (isset($parsed['path']) && $parsed['path'] !== '' && $parsed['path'] !== '/')
        ) {
            return null;
        }
        if ($parsed['scheme'] !== 'https' && !($parsed['scheme'] === 'http' && in_array($parsed['host'], ['localhost', '127.0.0.1', '::1'], true))) {
            return null;
        }

        return [
            'scheme' => $parsed['scheme'],
            'host' => strtolower($parsed['host']),
            ...isset($parsed['port']) ? ['port' => (int) $parsed['port']] : [],
        ];
    }

    private function userHandle(int $userId): string
    {
        return hash('sha256', 'lexnova-webauthn-user:' . $userId, true);
    }

    private function encodeId(string $rawId): string
    {
        return rtrim(strtr(base64_encode($rawId), '+/', '-_'), '=');
    }

    private function normaliseLabel(string $label): string
    {
        $label = trim($label);

        return $label !== '' ? mb_substr($label, 0, 100) : 'Passkey';
    }
}
