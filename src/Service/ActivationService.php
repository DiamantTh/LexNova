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
        $user = $this->db->fetchAssociative('SELECT id, username, activation_required FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            throw new \RuntimeException('User not found.');
        }
        if (!$recovery && !in_array($user['activation_required'], [true, 1, '1', 't', 'true'], true)) {
            throw new \RuntimeException('An activation ticket can only be issued for a pending account.');
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $tokenHash = hash('sha256', $token);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $createdAt = $now->format('Y-m-d H:i:s');
        $purpose = $recovery ? 'recovery' : 'activation';
        $this->db->transactional(function (Connection $db) use ($userId, $actorId, $purpose, $tokenHash, $createdAt, $now): void {
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
        });
        $this->sessions->revokeUser($userId);
        $this->audit->log(
            $actorId,
            $actorId !== null ? (string) ($this->db->fetchOne('SELECT username FROM users WHERE id = ?', [$actorId]) ?: '') : null,
            $recovery ? 'auth.recovery_activation_ticket_issued' : 'auth.activation_ticket_issued',
            'user:' . $userId,
            $recovery ? 'recovery;expires:24h' : 'activation;expires:24h',
            null,
            $userId,
        );

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

        return $row ? [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'purpose' => (string) $row['purpose'],
        ] : null;
    }

    /** @param callable(): int $registerCredential */
    public function complete(int $userId, string $tokenHash, callable $registerCredential): int
    {
        return $this->db->transactional(function (Connection $db) use ($userId, $tokenHash, $registerCredential): int {
            $consumedAt = gmdate('Y-m-d H:i:s');
            $affected = $db->executeStatement(
                'UPDATE user_activation_tickets SET consumed_at = ? WHERE user_id = ? AND token_hash = ? AND consumed_at IS NULL AND revoked_at IS NULL AND expires_at > ?',
                [$consumedAt, $userId, $tokenHash, $consumedAt],
            );
            if ($affected !== 1) {
                throw new \RuntimeException('Activation ticket expired or already used.');
            }
            $credentialId = $registerCredential();
            $db->update('users', ['activation_required' => false, 'mfa_required' => true], ['id' => $userId]);

            return $credentialId;
        });
    }
}
