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
    ) {
        $this->baseUrl = $this->parseBaseUrl($baseUrl);
        $this->serializer = (new WebauthnSerializerFactory(new AttestationStatementSupportManager()))->create();
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== null;
    }

    /** @param array{id: int, username: string} $user */
    public function createRegistrationOptions(array $user): string
    {
        $this->assertConfigured();
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
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            $this->credentialDescriptorsForUser($user['id']),
            60000,
        );

        return $this->serializer->serialize($options, 'json');
    }

    public function createAuthenticationOptions(): string
    {
        $this->assertConfigured();
        $options = PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            $this->rpId(),
            [],
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            60000,
        );

        return $this->serializer->serialize($options, 'json');
    }

    public function finishRegistration(int $userId, string $optionsJson, string $credentialJson, string $label): int
    {
        $this->assertConfigured();
        $options = $this->serializer->deserialize($optionsJson, PublicKeyCredentialCreationOptions::class, 'json');
        $credential = $this->serializer->deserialize($credentialJson, PublicKeyCredential::class, 'json');
        if (!$credential->response instanceof AuthenticatorAttestationResponse) {
            throw new \RuntimeException('Invalid passkey registration response.');
        }

        $source = $this->attestationValidator()->check($credential->response, $options, $this->rpId());
        if (!hash_equals($this->userHandle($userId), $source->userHandle)) {
            throw new \RuntimeException('Passkey does not belong to the current user.');
        }

        $this->db->insert('user_webauthn_credentials', [
            'user_id' => $userId,
            'credential_id' => $this->encodeId($source->publicKeyCredentialId),
            'credential_data' => $this->serializer->serialize($source, 'json'),
            'label' => $this->normaliseLabel($label),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @return array{id: int, username: string, role: string} */
    public function finishAuthentication(string $optionsJson, string $credentialJson): array
    {
        $this->assertConfigured();
        $options = $this->serializer->deserialize($optionsJson, PublicKeyCredentialRequestOptions::class, 'json');
        $credential = $this->serializer->deserialize($credentialJson, PublicKeyCredential::class, 'json');
        if (!$credential->response instanceof AuthenticatorAssertionResponse) {
            throw new \RuntimeException('Invalid passkey authentication response.');
        }

        $row = $this->findCredential($credential->rawId);
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

        $this->db->update('user_webauthn_credentials', [
            'credential_data' => $this->serializer->serialize($source, 'json'),
            'last_used_at' => date('Y-m-d H:i:s'),
        ], ['id' => $row['credential_id']]);

        return [
            'id' => (int) $row['user_id'],
            'username' => (string) $row['username'],
            'role' => (string) $row['role'],
        ];
    }

    /**
     * @return list<array{
     *   id: mixed, label: mixed, created_at: mixed, last_used_at: mixed,
     *   kind: string, transports: list<string>, aaguid: ?string,
     *   manufacturer: ?string, backup_eligible: ?bool, backup_status: ?bool
     * }>
     */
    public function listForUser(int $userId): array
    {
        $rows = $this->db->createQueryBuilder()
            ->select('id', 'label', 'created_at', 'last_used_at', 'credential_data')
            ->from('user_webauthn_credentials')
            ->where('user_id = :user_id')
            ->setParameter('user_id', $userId)
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
            'user_webauthn_credentials',
            ['label' => $this->normaliseLabel($label)],
            ['id' => $credentialId, 'user_id' => $userId],
        ) > 0;
    }

    public function deleteForUser(int $credentialId, int $userId): bool
    {
        return $this->db->delete('user_webauthn_credentials', ['id' => $credentialId, 'user_id' => $userId]) > 0;
    }

    /**
     * Manufacturer names are intentionally not guessed from transports or the
     * AAGUID. A reliable name needs trusted FIDO metadata; attestation "none"
     * can also deliberately suppress identifying information.
     *
     * @return array{
     *   kind: string, transports: list<string>, aaguid: ?string,
     *   manufacturer: ?string, backup_eligible: ?bool, backup_status: ?bool
     * }
     */
    private function credentialDetails(string $credentialData): array
    {
        $fallback = [
            'kind' => 'Passkey',
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
            $kind = match (true) {
                in_array('internal', $transports, true) => 'Plattform-Passkey',
                array_intersect(['usb', 'nfc', 'ble'], $transports) !== [] => 'FIDO2-Sicherheitsschlüssel',
                in_array('hybrid', $transports, true) => 'Hybrid-/Cross-Device-Passkey',
                $source->backupEligible === true => 'Synchronisierbarer Passkey',
                default => 'Passkey',
            };

            return [
                'kind' => $kind,
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
            ->from('user_webauthn_credentials')
            ->where('user_id = :user_id')
            ->setParameter('user_id', $userId)
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
    private function findCredential(string $rawId): ?array
    {
        $row = $this->db->createQueryBuilder()
            ->select('c.id AS credential_id', 'c.user_id', 'c.credential_data', 'u.username', 'u.role')
            ->from('user_webauthn_credentials', 'c')
            ->join('c', 'users', 'u', 'c.user_id = u.id')
            ->where('c.credential_id = :credential_id')
            ->setParameter('credential_id', $this->encodeId($rawId))
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
        $port = $this->baseUrl['port'] ?? null;
        $isDefaultPort = ($this->baseUrl['scheme'] === 'https' && $port === 443)
            || ($this->baseUrl['scheme'] === 'http' && $port === 80);

        return $this->baseUrl['scheme'] . '://' . $this->baseUrl['host']
            . ($port !== null && !$isDefaultPort ? ':' . $port : '');
    }

    private function rpId(): string
    {
        $this->assertConfigured();

        return $this->baseUrl['host'];
    }

    private function assertConfigured(): void
    {
        if ($this->baseUrl === null) {
            throw new \RuntimeException('Passkeys require a valid app.base_url configuration.');
        }
    }

    /** @return array{scheme: string, host: string, port?: int}|null */
    private function parseBaseUrl(string $baseUrl): ?array
    {
        $parsed = parse_url($baseUrl);
        if (!is_array($parsed) || !isset($parsed['scheme'], $parsed['host'])) {
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
