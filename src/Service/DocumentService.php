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
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return $this->db->createQueryBuilder()
            ->select('d.id', 'd.entity_id', 'd.public_hash', 'd.type', 'd.language', 'd.version',
                'd.updated_at', 'e.name AS entity_name')
            ->from('legal_documents', 'd')
            ->join('d', 'legal_entities', 'e', 'd.entity_id = e.id')
            ->orderBy('d.updated_at', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $row = $this->db->createQueryBuilder()
            ->select('id', 'entity_id', 'public_hash', 'type', 'language', 'content', 'version', 'updated_at')
            ->from('legal_documents')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public function findByPublicHashAndType(string $publicHash, string $type): ?array
    {
        $cacheKey = "doc_public_{$type}_{$publicHash}";

        if ($this->cache->has($cacheKey)) {
            /** @var array{found: bool, document: array<string, mixed>|null} $cached */
            $cached = $this->cache->get($cacheKey);

            return $cached['document'];
        }

        $row = $this->db->createQueryBuilder()
            ->select('id', 'entity_id', 'public_hash', 'type', 'language', 'content', 'version', 'updated_at')
            ->from('legal_documents')
            ->where('public_hash = :public_hash')
            ->andWhere('type = :type')
            ->setParameter('public_hash', $publicHash)
            ->setParameter('type', $type)
            ->executeQuery()
            ->fetchAssociative();
        $result = $row ?: null;
        if ($result !== null) {
            $this->storeCachedResult((int) $result['entity_id'], $type, $cacheKey, $result);
        }

        return $result;
    }

    public function create(int $entityId, string $type, string $language, string $content, string $version): int
    {
        $publicHash = bin2hex(random_bytes(16));

        $this->db->insert('legal_documents', [
            'entity_id' => $entityId,
            'public_hash' => $publicHash,
            'type' => $type,
            'language' => $language,
            'content' => $content,
            'version' => $version,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $id = (int) $this->db->lastInsertId();
        $this->invalidate($entityId, $type);

        return $id;
    }

    public function update(int $id, int $entityId, string $type, string $language, string $content, string $version): void
    {
        $previous = $this->findById($id);

        $this->db->update('legal_documents', [
            'entity_id' => $entityId,
            'type' => $type,
            'language' => $language,
            'content' => $content,
            'version' => $version,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        if ($previous !== null) {
            $this->invalidate((int) $previous['entity_id'], (string) $previous['type']);
        }
        $this->invalidate($entityId, $type);
    }

    /**
     * Returns the newest public document per language for an entity and type.
     *
     * @return array<string, string> language => public hash
     */
    public function listPublicVariants(int $entityId, string $type): array
    {
        $rows = $this->db->createQueryBuilder()
            ->select('language', 'public_hash')
            ->from('legal_documents')
            ->where('entity_id = :entity_id')
            ->andWhere('type = :type')
            ->setParameter('entity_id', $entityId)
            ->setParameter('type', $type)
            ->orderBy('language', 'ASC')
            ->addOrderBy('updated_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        $variants = [];
        foreach ($rows as $row) {
            $language = (string) $row['language'];
            $variants[$language] ??= (string) $row['public_hash'];
        }

        return $variants;
    }

    public function delete(int $id): void
    {
        $row = $this->findById($id);
        $this->db->delete('legal_documents', ['id' => $id]);
        if ($row !== null) {
            $this->invalidate((int) $row['entity_id'], (string) $row['type']);
        }
    }

    /** @param array<string, mixed>|null $document */
    private function storeCachedResult(int $entityId, string $type, string $key, ?array $document): void
    {
        $this->cache->set($key, ['found' => $document !== null, 'document' => $document], 3600);

        $indexKey = $this->indexKey($entityId, $type);
        /** @var list<string> $keys */
        $keys = $this->cache->get($indexKey, []);
        if (!in_array($key, $keys, true)) {
            $keys[] = $key;
            $this->cache->set($indexKey, $keys, 3600);
        }
    }

    private function invalidate(int $entityId, string $type): void
    {
        $indexKey = $this->indexKey($entityId, $type);
        /** @var list<string> $keys */
        $keys = $this->cache->get($indexKey, []);
        $this->cache->deleteMultiple(array_unique($keys));
        $this->cache->delete($indexKey);
    }

    private function indexKey(int $entityId, string $type): string
    {
        return "doc_cache_index_{$entityId}_{$type}";
    }
}
