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
            ->select('d.id', 'd.entity_id', 'd.type', 'd.language', 'd.version',
                'd.updated_at', 'e.name AS entity_name', 'e.hash AS entity_hash')
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
            ->select('id', 'entity_id', 'type', 'language', 'content', 'version', 'updated_at')
            ->from('legal_documents')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }

    /**
     * Returns the latest document for a given entity + type.
     *
     * If $language is provided, tries an exact BCP 47 match first, then falls
     * back to the newest document of that type regardless of language.
     * The cache key includes the requested language so each variant is cached
     * independently. Every generated key is indexed per entity/type and is
     * invalidated on any write, including a document being moved to a different
     * entity or type.
     */
    /** @return array<string, mixed>|null */
    public function findLatest(int $entityId, string $type, ?string $language = null): ?array
    {
        $lang = $language !== null ? strtolower(trim($language)) : null;
        $cacheKey = $lang !== null
            ? "doc_latest_{$entityId}_{$type}_{$lang}"
            : "doc_latest_{$entityId}_{$type}";

        if ($this->cache->has($cacheKey)) {
            /** @var array{found: bool, document: array<string, mixed>|null} $cached */
            $cached = $this->cache->get($cacheKey);

            return $cached['document'];
        }

        $base = $this->db->createQueryBuilder()
            ->select('id', 'entity_id', 'type', 'language', 'content', 'version', 'updated_at')
            ->from('legal_documents')
            ->where('entity_id = :entity_id')
            ->andWhere('type = :type')
            ->setParameter('entity_id', $entityId)
            ->setParameter('type', $type)
            ->orderBy('updated_at', 'DESC')
            ->addOrderBy('id', 'DESC');

        // Try exact language match first
        if ($lang !== null) {
            $row = (clone $base)
                ->andWhere('LOWER(language) = :lang')
                ->setParameter('lang', $lang)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            if ($row !== false) {
                $this->storeCachedResult($entityId, $type, $cacheKey, $row);

                return $row;
            }
        }

        // No language requested or no exact match → newest of any language
        $row = $base->setMaxResults(1)->executeQuery()->fetchAssociative();
        $result = $row ?: null;
        $this->storeCachedResult($entityId, $type, $cacheKey, $result);

        return $result;
    }

    public function create(int $entityId, string $type, string $language, string $content, string $version): int
    {
        $this->db->insert('legal_documents', [
            'entity_id' => $entityId,
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
     * Returns all language codes that exist for a given entity + type,
     * ordered alphabetically. Used to build hreflang and language-switcher links.
     *
     * @return list<string>
     */
    public function listLanguageVariants(int $entityId, string $type): array
    {
        $rows = $this->db->createQueryBuilder()
            ->select('language')
            ->from('legal_documents')
            ->where('entity_id = :entity_id')
            ->andWhere('type = :type')
            ->setParameter('entity_id', $entityId)
            ->setParameter('type', $type)
            ->orderBy('language', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_unique($rows));
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
        $keys[] = "doc_latest_{$entityId}_{$type}";
        $this->cache->deleteMultiple(array_unique($keys));
        $this->cache->delete($indexKey);
    }

    private function indexKey(int $entityId, string $type): string
    {
        return "doc_cache_index_{$entityId}_{$type}";
    }
}
