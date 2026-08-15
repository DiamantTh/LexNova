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

        try {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached) && ($cached['found'] ?? false) === true && is_array($cached['document'] ?? null)) {
                /** @var array<string, mixed> $document */
                $document = $cached['document'];

                return $document;
            }
        } catch (\Throwable) {
            // The database remains authoritative when a cache backend fails.
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
        try {
            // No explicit TTL: the selected named cache area owns this policy
            // (config cache.default_ttl for documents).
            $this->cache->set($key, ['found' => $document !== null, 'document' => $document]);

            $indexKey = $this->indexKey($entityId, $type);
            $cachedKeys = $this->cache->get($indexKey, []);
            /** @var list<string> $keys */
            $keys = is_array($cachedKeys) ? array_values(array_filter($cachedKeys, 'is_string')) : [];
            if (!in_array($key, $keys, true)) {
                $keys[] = $key;
                $this->cache->set($indexKey, $keys);
            }
        } catch (\Throwable) {
            // Cache population is an optimization and must not fail a write.
        }
    }

    private function invalidate(int $entityId, string $type): void
    {
        $indexKey = $this->indexKey($entityId, $type);
        try {
            $cachedKeys = $this->cache->get($indexKey, []);
            /** @var list<string> $keys */
            $keys = is_array($cachedKeys) ? array_values(array_filter($cachedKeys, 'is_string')) : [];
            $this->cache->deleteMultiple(array_unique($keys));
            $this->cache->delete($indexKey);
        } catch (\Throwable) {
            // Cached public documents expire; database writes remain authoritative.
        }
    }

    private function indexKey(int $entityId, string $type): string
    {
        return "doc_cache_index_{$entityId}_{$type}";
    }
}
