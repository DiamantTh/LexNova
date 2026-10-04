<?php

declare(strict_types=1);

namespace LexNova\Service;

use Doctrine\DBAL\Connection;
use Mezzio\Session\SessionInterface;

/** Persists revocable authentication sessions without storing PHP session IDs. */
final readonly class AuthSessionService
{
    public function __construct(
        private Connection $db,
        private int $idleSeconds = 1800,
        private int $absoluteSeconds = 43200,
    ) {
    }

    public function establish(SessionInterface $session, int $userId, string $method, string $strength): int
    {
        $phpSessionId = session_id();
        if ($phpSessionId === '') {
            throw new \RuntimeException('Cannot persist authentication without an active session.');
        }

        return $this->db->transactional(function (Connection $db) use ($session, $userId, $method, $strength, $phpSessionId): int {
            // A no-op write locks the account row on SQLite, MySQL/MariaDB and
            // PostgreSQL. Recovery's activation_required update uses the same
            // row, making this check and session insertion atomic with it.
            $db->executeStatement('UPDATE users SET activation_required = activation_required WHERE id = ?', [$userId]);
            $user = $db->fetchAssociative('SELECT activation_required FROM users WHERE id = ?', [$userId]);
            if (!$user || $this->databaseBool($user['activation_required'])) {
                throw new \RuntimeException('Account activation or recovery is required before sign-in.');
            }

            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $createdAt = $now->format('Y-m-d H:i:s');
            $sessionHash = hash('sha256', $phpSessionId);
            $db->insert('user_sessions', [
                'user_id' => $userId,
                'session_hash' => $sessionHash,
                'auth_method' => $method,
                'auth_strength' => $strength,
                'created_at' => $createdAt,
                'last_activity_at' => $createdAt,
                'absolute_expires_at' => $now->modify('+' . max(60, $this->absoluteSeconds) . ' seconds')->format('Y-m-d H:i:s'),
            ]);
            $id = (int) $db->fetchOne('SELECT id FROM user_sessions WHERE session_hash = ?', [$sessionHash]);
            $session->set('auth_session_id', $id);
            $session->set('auth_method', $method);
            $session->set('auth_strength', $strength);

            return $id;
        });
    }

    public function isValid(SessionInterface $session, int $userId): bool
    {
        $id = (int) ($session->get('auth_session_id') ?? 0);
        if ($id <= 0 || $userId <= 0) {
            return false;
        }
        $row = $this->db->createQueryBuilder()
            ->select('s.id', 's.last_activity_at', 's.absolute_expires_at', 's.revoked_at', 'u.role', 'u.activation_required')
            ->from('user_sessions', 's')
            ->join('s', 'users', 'u', 's.user_id = u.id')
            ->where('s.id = :id AND s.user_id = :user_id')
            ->setParameter('id', $id)
            ->setParameter('user_id', $userId)
            ->executeQuery()
            ->fetchAssociative();
        if (!$row || $row['revoked_at'] !== null || $this->databaseBool($row['activation_required'])
            || (string) $row['role'] !== (string) $session->get('role')
        ) {
            if ($row && $this->databaseBool($row['activation_required'])) {
                $this->revoke($id);
            }

            return false;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $last = new \DateTimeImmutable((string) $row['last_activity_at'], new \DateTimeZone('UTC'));
        $absolute = new \DateTimeImmutable((string) $row['absolute_expires_at'], new \DateTimeZone('UTC'));
        if ($last->modify('+' . max(60, $this->idleSeconds) . ' seconds') <= $now || $absolute <= $now) {
            $this->revoke($id);

            return false;
        }

        $this->db->update('user_sessions', ['last_activity_at' => $now->format('Y-m-d H:i:s')], ['id' => $id]);

        return true;
    }

    public function revoke(int $sessionId): void
    {
        $this->db->update('user_sessions', ['revoked_at' => gmdate('Y-m-d H:i:s')], [
            'id' => $sessionId,
            'revoked_at' => null,
        ]);
    }

    public function revokeUser(int $userId): int
    {
        return $this->db->executeStatement(
            'UPDATE user_sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL',
            [gmdate('Y-m-d H:i:s'), $userId],
        );
    }

    public function revokeOtherSessions(int $userId, int $currentSessionId): int
    {
        return $this->db->executeStatement(
            'UPDATE user_sessions SET revoked_at = ? WHERE user_id = ? AND id <> ? AND revoked_at IS NULL',
            [gmdate('Y-m-d H:i:s'), $userId, $currentSessionId],
        );
    }

    private function databaseBool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }
}
