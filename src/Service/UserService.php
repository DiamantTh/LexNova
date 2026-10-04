<?php

declare(strict_types=1);

namespace LexNova\Service;

use Doctrine\DBAL\Connection;

final readonly class UserService
{
    /**
     * Valid Argon2id hash used to keep failed login timing comparable when a
     * username does not exist. Authentication still always fails for this row.
     */
    private const DUMMY_PASSWORD_HASH = '$argon2id$v=19$m=131072,t=4,p=2$dER4OE9RUVNVSWk4ODdhSA$UoC4SfZrDYxzxi7i7AJvhTmYV+NUqu0u+ZQFxkecLfY';

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
            ->select('id', 'username', 'role', 'password_login_enabled', 'mfa_required', 'activation_required', 'created_at')
            ->from('users')
            ->orderBy('username', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        // Annotate each user with TOTP key count for the dashboard
        foreach ($users as &$user) {
            $user['totp_key_count'] = $this->countActiveKeys((int) $user['id']);
            $user['passkey_count'] = $this->countPasskeys((int) $user['id']);
            $user['password_login_enabled'] = $this->databaseBool($user['password_login_enabled']);
            $user['mfa_required'] = $this->databaseBool($user['mfa_required']);
            $user['activation_required'] = $this->databaseBool($user['activation_required']);
        }

        return $users;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $row = $this->db->createQueryBuilder()
            ->select('id', 'username', 'role', 'password_login_enabled', 'mfa_required', 'activation_required', 'created_at')
            ->from('users')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative();

        if (!$row) {
            return null;
        }
        $row['password_login_enabled'] = $this->databaseBool($row['password_login_enabled']);
        $row['mfa_required'] = $this->databaseBool($row['mfa_required']);
        $row['activation_required'] = $this->databaseBool($row['activation_required']);

        return $row;
    }

    /** @return array<string,mixed>|null */
    public function findByUsername(string $username): ?array
    {
        $row = $this->db->createQueryBuilder()
            ->select('id', 'username', 'password_hash', 'password_login_enabled', 'mfa_required', 'activation_required', 'role')
            ->from('users')
            ->where('username = :username')
            ->setParameter('username', $username)
            ->executeQuery()
            ->fetchAssociative();

        if (!$row) {
            return null;
        }
        $row['password_login_enabled'] = $this->databaseBool($row['password_login_enabled']);
        $row['mfa_required'] = $this->databaseBool($row['mfa_required']);
        $row['activation_required'] = $this->databaseBool($row['activation_required']);

        return $row;
    }

    /** @return array<string,mixed>|null */
    public function verifyCredentials(string $username, string $password): ?array
    {
        $user = $this->findByUsername($username);
        $passwordLoginEnabled = $user !== null && $user['password_login_enabled'] === true;
        $hash = $passwordLoginEnabled ? (string) $user['password_hash'] : self::DUMMY_PASSWORD_HASH;

        if (!$this->passwords->verify($password, $hash) || $user === null || !$passwordLoginEnabled || $user['activation_required']) {
            return null;
        }

        if ($this->passwords->needsRehash($hash)) {
            $newHash = $this->passwords->hash($password);
            $this->db->update('users', ['password_hash' => $newHash], ['id' => (int) $user['id']]);
            $user['password_hash'] = $newHash;
        }

        return $user;
    }

    public function create(
        string $username,
        string $password,
        string $role = 'admin',
        bool $passwordLoginEnabled = true,
        bool $activationRequired = false,
    ): int {
        $hash = $this->passwords->hash(
            $passwordLoginEnabled ? $password : bin2hex(random_bytes(32)),
        );

        $this->db->insert('users', [
            'username' => $username,
            'password_hash' => $hash,
            'password_login_enabled' => $passwordLoginEnabled,
            'activation_required' => $activationRequired,
            'role' => $role,
            'created_at' => gmdate('Y-m-d H:i:s'),
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

    public function setPasswordLoginEnabled(int $id, bool $enabled): void
    {
        $this->db->update('users', ['password_login_enabled' => $enabled], ['id' => $id]);
    }

    public function setActivationRequired(int $id, bool $required): void
    {
        $this->db->update('users', ['activation_required' => $required], ['id' => $id]);
    }

    public function countPasskeys(int $userId): int
    {
        return (int) $this->db->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('user_authenticators')
            ->where('user_id = :uid AND kind = :kind')
            ->setParameter('uid', $userId)
            ->setParameter('kind', 'webauthn')
            ->executeQuery()
            ->fetchOne();
    }

    public function hasPasskey(int $userId): bool
    {
        return $this->countPasskeys($userId) > 0;
    }

    public function mfaRequired(int $userId): bool
    {
        $value = $this->db->fetchOne('SELECT mfa_required FROM users WHERE id = ?', [$userId]);

        return $this->databaseBool($value);
    }

    public function delete(int $id): void
    {
        $this->db->delete('users', ['id' => $id]);
    }

    public function countAdministrators(): int
    {
        return (int) $this->db->fetchOne("SELECT COUNT(*) FROM users WHERE role = 'admin'");
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
            ->select('id', 'user_id', 'label', 'is_active', 'created_at', 'last_used_at')
            ->from('user_authenticators')
            ->where('user_id = :uid AND kind = :kind')
            ->setParameter('uid', $userId)
            ->setParameter('kind', 'totp')
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
            ->from('user_authenticators')
            ->where('user_id = :uid AND kind = :kind AND is_active = TRUE')
            ->setParameter('uid', $userId)
            ->setParameter('kind', 'totp')
            ->orderBy('id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function countActiveKeys(int $userId): int
    {
        return (int) $this->db->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('user_authenticators')
            ->where('user_id = :uid AND kind = :kind AND is_active = TRUE')
            ->setParameter('uid', $userId)
            ->setParameter('kind', 'totp')
            ->executeQuery()
            ->fetchOne();
    }

    public function countTotpKeys(int $userId): int
    {
        return (int) $this->db->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('user_authenticators')
            ->where('user_id = :uid AND kind = :kind')
            ->setParameter('uid', $userId)
            ->setParameter('kind', 'totp')
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
        $this->db->insert('user_authenticators', [
            'user_id' => $userId,
            'kind' => 'totp',
            'label' => $label,
            'secret_enc' => $encryptedSecret,
            'is_active' => true,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->db->update('users', ['mfa_required' => true], ['id' => $userId]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Deactivates one specific TOTP key (does not delete).
     * Returns false if the key does not belong to the user.
     */
    public function deactivateTotpKey(int $keyId, int $userId): bool
    {
        $affected = $this->db->update(
            'user_authenticators',
            ['is_active' => false],
            ['id' => $keyId, 'user_id' => $userId, 'kind' => 'totp'],
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
            'user_authenticators',
            ['id' => $keyId, 'user_id' => $userId, 'kind' => 'totp'],
        );

        return $affected > 0;
    }

    /**
     * Records a successful use of a specific key (updates last_used_at).
     */
    public function touchTotpKey(int $keyId): void
    {
        $this->db->update(
            'user_authenticators',
            ['last_used_at' => gmdate('Y-m-d H:i:s')],
            ['id' => $keyId, 'kind' => 'totp'],
        );
    }

    /**
     * Wipes all TOTP keys for a user (admin reset / recovery).
     */
    public function deleteAllTotpKeys(int $userId): int
    {
        return (int) $this->db->delete('user_authenticators', ['user_id' => $userId, 'kind' => 'totp']);
    }

    private function databaseBool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }
}
