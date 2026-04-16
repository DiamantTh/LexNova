<?php

declare(strict_types=1);

namespace LexNova\Service;

use Doctrine\DBAL\Connection;

final readonly class UserService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PasswordService $passwords,
    ) {}

    public function list(): array
    {
        return $this->db->createQueryBuilder()
            ->select('id', 'username', 'role', 'created_at')
            ->from('users')
            ->orderBy('username', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

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
            'username'      => $username,
            'password_hash' => $hash,
            'role'          => $role,
            'created_at'    => date('Y-m-d H:i:s'),
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
}
