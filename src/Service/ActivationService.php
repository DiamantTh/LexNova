<?php

declare(strict_types=1);

namespace LexNova\Service;

use Doctrine\DBAL\Connection;

/** One-time enrollment and audited account recovery tickets. */
final readonly class ActivationService
{
    public function __construct(
        private Connection $db,
        private AuditService $audit,
        private AuthSessionService $sessions,
    ) {
    }

    public function issue(int $userId, ?int $actorId = null, bool $recovery = false): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $tokenHash = hash('sha256', $token);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $createdAt = $now->format('Y-m-d H:i:s');
        $purpose = $recovery ? 'recovery' : 'activation';
        $this->db->transactional(function (Connection $db) use ($userId, $actorId, $purpose, $tokenHash, $createdAt, $now, $recovery): void {
            $db->executeStatement('UPDATE users SET activation_required = activation_required WHERE id = ?', [$userId]);
            $user = $db->fetchAssociative('SELECT id, username, activation_required FROM users WHERE id = ?', [$userId]);
            if (!$user) {
                throw new \RuntimeException('User not found.');
            }
            if (!$recovery && !$this->databaseBool($user['activation_required'])) {
                throw new \RuntimeException('An activation ticket can only be issued for a pending account.');
            }
            $db->executeStatement(
                'UPDATE user_activation_tickets SET revoked_at = ? WHERE user_id = ? AND consumed_at IS NULL AND revoked_at IS NULL',
                [$createdAt, $userId],
            );
            $db->update('users', ['activation_required' => true], ['id' => $userId]);
            $db->insert('user_activation_tickets', [
                'user_id' => $userId,
                'token_hash' => $tokenHash,
                'purpose' => $purpose,
                'created_by_user_id' => $actorId,
                'created_at' => $createdAt,
                'expires_at' => $now->modify('+24 hours')->format('Y-m-d H:i:s'),
            ]);
            $this->sessions->revokeUser($userId);
            $actorName = $actorId !== null
                ? (string) ($db->fetchOne('SELECT username FROM users WHERE id = ?', [$actorId]) ?: '')
                : null;
            $this->audit->log(
                $actorId,
                $actorName,
                $recovery ? 'auth.recovery_activation_ticket_issued' : 'auth.activation_ticket_issued',
                'user:' . $userId,
                $recovery ? 'recovery;expires:24h' : 'activation;expires:24h',
                null,
                $userId,
            );
        });

        return $token;
    }

    /** @return array{id: int, username: string, purpose: string}|null */
    public function userForTicketHash(string $tokenHash): ?array
    {
        $row = $this->db->createQueryBuilder()
            ->select('u.id', 'u.username', 't.purpose')
            ->from('user_activation_tickets', 't')
            ->join('t', 'users', 'u', 'u.id = t.user_id')
            ->where('t.token_hash = :token_hash AND t.consumed_at IS NULL AND t.revoked_at IS NULL AND t.expires_at > :now AND u.activation_required = TRUE')
            ->setParameter('token_hash', $tokenHash)
            ->setParameter('now', gmdate('Y-m-d H:i:s'))
            ->executeQuery()
            ->fetchAssociative();

        if (!$row || !in_array((string) $row['purpose'], ['activation', 'recovery'], true)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'purpose' => (string) $row['purpose'],
        ];
    }

    /** @return array{user_id: int, purpose: string}|null For audit only; never returns the ticket hash. */
    public function ticketAuditContext(string $tokenHash): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT user_id, purpose FROM user_activation_tickets WHERE token_hash = ?',
            [$tokenHash],
        );
        if (!$row || !in_array((string) $row['purpose'], ['activation', 'recovery'], true)) {
            return null;
        }

        return ['user_id' => (int) $row['user_id'], 'purpose' => (string) $row['purpose']];
    }

    /** Keep the intent of a pending flow when its ticket is reissued. */
    public function latestTicketPurpose(int $userId): ?string
    {
        $purpose = $this->db->fetchOne(
            'SELECT purpose FROM user_activation_tickets WHERE user_id = ? ORDER BY id DESC',
            [$userId],
        );

        return in_array($purpose, ['activation', 'recovery'], true) ? (string) $purpose : null;
    }

    /**
     * @param callable(): int $registerCredential must verify and persist a new FIDO2 credential using this connection
     */
    public function complete(int $userId, string $tokenHash, callable $registerCredential, ?string $ip = null): int
    {
        return $this->db->transactional(function (Connection $db) use ($userId, $tokenHash, $registerCredential, $ip): int {
            $consumedAt = gmdate('Y-m-d H:i:s');
            $db->executeStatement('UPDATE users SET activation_required = activation_required WHERE id = ?', [$userId]);
            $user = $db->fetchAssociative('SELECT username, activation_required FROM users WHERE id = ?', [$userId]);
            if (!$user || !$this->databaseBool($user['activation_required'])) {
                throw new \RuntimeException('Account is not awaiting activation or recovery.');
            }
            $ticket = $db->fetchAssociative(
                'SELECT purpose FROM user_activation_tickets WHERE user_id = ? AND token_hash = ? AND consumed_at IS NULL AND revoked_at IS NULL AND expires_at > ?',
                [$userId, $tokenHash, $consumedAt],
            );
            if (!$ticket) {
                throw new \RuntimeException('Activation ticket expired or already used.');
            }
            $purpose = (string) $ticket['purpose'];
            if (!in_array($purpose, ['activation', 'recovery'], true)) {
                throw new \RuntimeException('Activation ticket purpose is invalid.');
            }
            $existingWebauthnIds = array_map(
                'intval',
                $db->fetchFirstColumn(
                    "SELECT id FROM user_authenticators WHERE user_id = ? AND kind = 'webauthn'",
                    [$userId],
                ),
            );
            $credentialId = $registerCredential();
            if ($credentialId <= 0
                || in_array($credentialId, $existingWebauthnIds, true)
                || (int) $db->fetchOne(
                    "SELECT COUNT(*) FROM user_authenticators WHERE id = ? AND user_id = ? AND kind = 'webauthn'",
                    [$credentialId, $userId],
                ) !== 1
            ) {
                throw new \RuntimeException('A newly verified FIDO2 credential was not persisted for this account.');
            }

            if ($purpose === 'recovery') {
                $this->audit->log(
                    $userId,
                    (string) $user['username'],
                    'auth.recovery_webauthn_registered',
                    'user:' . $userId,
                    'authenticator_id:' . $credentialId,
                    $ip,
                    $userId,
                );
                $oldWebauthnCount = (int) $db->fetchOne(
                    "SELECT COUNT(*) FROM user_authenticators WHERE user_id = ? AND id <> ? AND kind = 'webauthn'",
                    [$userId, $credentialId],
                );
                $oldTotpCount = (int) $db->fetchOne(
                    "SELECT COUNT(*) FROM user_authenticators WHERE user_id = ? AND id <> ? AND kind = 'totp'",
                    [$userId, $credentialId],
                );
                $deleted = $db->executeStatement(
                    "DELETE FROM user_authenticators WHERE user_id = ? AND id <> ? AND kind IN ('webauthn', 'totp')",
                    [$userId, $credentialId],
                );
                if ($deleted !== $oldWebauthnCount + $oldTotpCount) {
                    throw new \RuntimeException('Pre-recovery credential cleanup was incomplete.');
                }
                $this->audit->log(
                    $userId,
                    (string) $user['username'],
                    'auth.recovery_webauthn_credentials_revoked',
                    'user:' . $userId,
                    'count:' . $oldWebauthnCount,
                    $ip,
                    $userId,
                );
                $this->audit->log(
                    $userId,
                    (string) $user['username'],
                    'auth.recovery_totp_credentials_revoked',
                    'user:' . $userId,
                    'count:' . $oldTotpCount,
                    $ip,
                    $userId,
                );
            }

            $updated = $db->update('users', ['activation_required' => false, 'mfa_required' => true], ['id' => $userId]);
            if ($updated !== 1) {
                throw new \RuntimeException('Account recovery could not be completed.');
            }
            $affected = $db->executeStatement(
                'UPDATE user_activation_tickets SET consumed_at = ? WHERE user_id = ? AND token_hash = ? AND consumed_at IS NULL AND revoked_at IS NULL AND expires_at > ?',
                [$consumedAt, $userId, $tokenHash, $consumedAt],
            );
            if ($affected !== 1) {
                throw new \RuntimeException('Activation ticket expired or already used.');
            }
            $this->audit->log(
                $userId,
                (string) $user['username'],
                $purpose === 'recovery' ? 'auth.recovery_activation_completed' : 'auth.activation_completed',
                'user:' . $userId,
                'webauthn:' . $credentialId,
                $ip,
                $userId,
            );

            return $credentialId;
        });
    }

    private function databaseBool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }
}
