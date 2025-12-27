<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function list_documents(): array
{
    $stmt = db()->query('SELECT d.id, d.entity_id, d.type, d.language, d.version, d.updated_at, e.name AS entity_name, e.hash AS entity_hash FROM legal_documents d JOIN legal_entities e ON d.entity_id = e.id ORDER BY d.updated_at DESC');
    return $stmt->fetchAll();
}

function get_document(int $id): ?array
{
    $stmt = db()->prepare('SELECT id, entity_id, type, language, content, version, updated_at FROM legal_documents WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $doc = $stmt->fetch();

    return $doc ?: null;
}

function get_latest_document(int $entityId, string $type): ?array
{
    $stmt = db()->prepare('SELECT id, entity_id, type, language, content, version, updated_at FROM legal_documents WHERE entity_id = :entity_id AND type = :type ORDER BY updated_at DESC, id DESC LIMIT 1');
    $stmt->execute([
        ':entity_id' => $entityId,
        ':type' => $type,
    ]);
    $doc = $stmt->fetch();

    return $doc ?: null;
}

function create_document(int $entityId, string $type, string $language, string $content, string $version): int
{
    $stmt = db()->prepare('INSERT INTO legal_documents (entity_id, type, language, content, version, updated_at) VALUES (:entity_id, :type, :language, :content, :version, :updated_at)');
    $stmt->execute([
        ':entity_id' => $entityId,
        ':type' => $type,
        ':language' => $language,
        ':content' => $content,
        ':version' => $version,
        ':updated_at' => date('Y-m-d H:i:s'),
    ]);

    return (int) db()->lastInsertId();
}

function update_document(int $id, int $entityId, string $type, string $language, string $content, string $version): void
{
    $stmt = db()->prepare('UPDATE legal_documents SET entity_id = :entity_id, type = :type, language = :language, content = :content, version = :version, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
        ':id' => $id,
        ':entity_id' => $entityId,
        ':type' => $type,
        ':language' => $language,
        ':content' => $content,
        ':version' => $version,
        ':updated_at' => date('Y-m-d H:i:s'),
    ]);
}
