<?php

declare(strict_types=1);

namespace LexNova\Service;

use Doctrine\DBAL\Connection;

final readonly class UserService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PasswordService $passwords,
    ) {
    }

    // ── Users ────────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        $users = $this->db->createQueryBuilder()
            ->select('id', 'username', 'role', 'created_at')
            ->from('users')
            ->orderBy('username', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        // Annotate each user with TOTP key count for the dashboard
        foreach ($users as &$user) {
            $user['totp_key_count'] = $this->countActiveKeys((int) $user['id']);
        }

        return $users;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $row = $this->db->createQueryBuilder()
            ->select('id', 'username', 'role', 'created_at')
            ->from('users')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findByUsername(string $username): ?array
    {
        $row = $this->db->createQueryBuilder()
            ->select('id', 'username', 'password_hash', 'role')
            ->from('users')
            ->where('username = :username')
            ->setParameter('username', $username)
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function verifyCredentials(string $username, string $password): ?array
    {
        $user = $this->findByUsername($username);
        if ($user === null) {
            return null;
        }

        if (!$this->passwords->verify($password, (string) $user['password_hash'])) {
            return null;
        }

        return $user;
    }

    public function create(string $username, string $password, string $role = 'admin'): int
    {
        $hash = $this->passwords->hash($password);

        $this->db->insert('users', [
            'username' => $username,
            'password_hash' => $hash,
            'role' => $role,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateRole(int $id, string $role): void
    {
        $this->db->update('users', ['role' => $role], ['id' => $id]);
    }

    public function updatePassword(int $id, string $password): void
    {
        $hash = $this->passwords->hash($password);
        $this->db->update('users', ['password_hash' => $hash], ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->delete('users', ['id' => $id]);
    }

    // ── TOTP keys (one user may have multiple) ───────────────────────────────

    /**
     * Returns all TOTP keys for a user (active and inactive), newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function getTotpKeys(int $userId): array
    {
        return $this->db->createQueryBuilder()
            ->select('id', 'user_id', 'label', 'secret_enc', 'is_active', 'created_at', 'last_used_at')
            ->from('user_totp_keys')
            ->where('user_id = :uid')
            ->setParameter('uid', $userId)
            ->orderBy('id', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Returns only the active TOTP keys for a user.
     *
     * @return list<array<string,mixed>>
     */
    public function getActiveTotpKeys(int $userId): array
    {
        return $this->db->createQueryBuilder()
            ->select('id', 'secret_enc', 'label')
            ->from('user_totp_keys')
            ->where('user_id = :uid AND is_active = 1')
            ->setParameter('uid', $userId)
            ->orderBy('id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function countActiveKeys(int $userId): int
    {
        return (int) $this->db->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('user_totp_keys')
            ->where('user_id = :uid AND is_active = 1')
            ->setParameter('uid', $userId)
            ->executeQuery()
            ->fetchOne();
    }

    public function hasActiveTotpKey(int $userId): bool
    {
        return $this->countActiveKeys($userId) > 0;
    }

    /**
     * Adds a new TOTP key for the user.
     *
     * @return int New key ID
     */
    public function addTotpKey(int $userId, string $encryptedSecret, string $label = 'Default'): int
    {
        $this->db->insert('user_totp_keys', [
            'user_id' => $userId,
            'label' => $label,
            'secret_enc' => $encryptedSecret,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Deactivates one specific TOTP key (does not delete).
     * Returns false if the key does not belong to the user.
     */
    public function deactivateTotpKey(int $keyId, int $userId): bool
    {
        $affected = $this->db->update(
            'user_totp_keys',
            ['is_active' => 0],
            ['id' => $keyId, 'user_id' => $userId],
        );

        return $affected > 0;
    }

    /**
     * Permanently deletes a specific TOTP key.
     * Returns false if the key does not belong to the user.
     */
    public function deleteTotpKey(int $keyId, int $userId): bool
    {
        $affected = $this->db->delete(
            'user_totp_keys',
            ['id' => $keyId, 'user_id' => $userId],
        );

        return $affected > 0;
    }

    /**
     * Records a successful use of a specific key (updates last_used_at).
     */
    public function touchTotpKey(int $keyId): void
    {
        $this->db->update(
            'user_totp_keys',
            ['last_used_at' => date('Y-m-d H:i:s')],
            ['id' => $keyId],
        );
    }

    /**
     * Wipes all TOTP keys for a user (admin reset / recovery).
     */
    public function deleteAllTotpKeys(int $userId): int
    {
        return (int) $this->db->delete('user_totp_keys', ['user_id' => $userId]);
    }
}
