<?php

declare(strict_types=1);

namespace LexNova\Service;

use Doctrine\DBAL\Connection;
use Psr\SimpleCache\CacheInterface;

final readonly class DocumentService
{
    public function __construct(
        private readonly Connection $db,
        private readonly CacheInterface $cache,
    ) {}

    public function list(): array
    {
        return $this->db->createQueryBuilder()
            ->select('d.id', 'd.entity_id', 'd.type', 'd.language', 'd.version',
                     'd.updated_at', 'e.name AS entity_name', 'e.hash AS entity_hash')
            ->from('legal_documents', 'd')
            ->join('d', 'legal_entities', 'e', 'd.entity_id = e.id')
            ->orderBy('d.updated_at', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function findById(int $id): ?array
    {
        $row = $this->db->createQueryBuilder()
            ->select('id', 'entity_id', 'type', 'language', 'content', 'version', 'updated_at')
            ->from('legal_documents')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }

    public function findLatest(int $entityId, string $type): ?array
    {
        $key = "doc_latest_{$entityId}_{$type}";

        if ($this->cache->has($key)) {
            return $this->cache->get($key);
        }

        $row = $this->db->createQueryBuilder()
            ->select('id', 'entity_id', 'type', 'language', 'content', 'version', 'updated_at')
            ->from('legal_documents')
            ->where('entity_id = :entity_id')
            ->andWhere('type = :type')
            ->setParameter('entity_id', $entityId)
            ->setParameter('type', $type)
            ->orderBy('updated_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        $result = $row ?: null;
        $this->cache->set($key, $result, 3600);

        return $result;
    }

    public function create(int $entityId, string $type, string $language, string $content, string $version): int
    {
        $this->db->insert('legal_documents', [
            'entity_id'  => $entityId,
            'type'       => $type,
            'language'   => $language,
            'content'    => $content,
            'version'    => $version,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $id = (int) $this->db->lastInsertId();
        $this->cache->delete("doc_latest_{$entityId}_{$type}");

        return $id;
    }

    public function update(int $id, int $entityId, string $type, string $language, string $content, string $version): void
    {
        $this->db->update('legal_documents', [
            'entity_id'  => $entityId,
            'type'       => $type,
            'language'   => $language,
            'content'    => $content,
            'version'    => $version,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        $this->cache->delete("doc_latest_{$entityId}_{$type}");
    }
}
