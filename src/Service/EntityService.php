<?php

declare(strict_types=1);

namespace LexNova\Service;

use Doctrine\DBAL\Connection;

final readonly class EntityService
{
    public function __construct(private readonly Connection $db) {}

    public function list(): array
    {
        return $this->db->createQueryBuilder()
            ->select('id', 'hash', 'name', 'contact_data')
            ->from('legal_entities')
            ->orderBy('name', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function findByHash(string $hash): ?array
    {
        $row = $this->db->createQueryBuilder()
            ->select('id', 'hash', 'name', 'contact_data')
            ->from('legal_entities')
            ->where('hash = :hash')
            ->setParameter('hash', $hash)
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $row = $this->db->createQueryBuilder()
            ->select('id', 'hash', 'name', 'contact_data')
            ->from('legal_entities')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }

    public function create(string $name, string $contactData): array
    {
        $hash = $this->generateUniqueHash();

        $this->db->insert('legal_entities', [
            'hash'         => $hash,
            'name'         => $name,
            'contact_data' => $contactData,
        ]);

        return [
            'id'   => (int) $this->db->lastInsertId(),
            'hash' => $hash,
        ];
    }

    private function generateUniqueHash(): string
    {
        do {
            $hash  = bin2hex(random_bytes(16));
            $count = (int) $this->db->createQueryBuilder()
                ->select('COUNT(*)')
                ->from('legal_entities')
                ->where('hash = :hash')
                ->setParameter('hash', $hash)
                ->executeQuery()
                ->fetchOne();
        } while ($count > 0);

        return $hash;
    }
}
