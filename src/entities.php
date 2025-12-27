<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function list_entities(): array
{
    $stmt = db()->query('SELECT id, hash, name, contact_data FROM legal_entities ORDER BY name ASC');
    return $stmt->fetchAll();
}

function get_entity_by_hash(string $hash): ?array
{
    $stmt = db()->prepare('SELECT id, hash, name, contact_data FROM legal_entities WHERE hash = :hash');
    $stmt->execute([':hash' => $hash]);
    $entity = $stmt->fetch();

    return $entity ?: null;
}

function get_entity_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT id, hash, name, contact_data FROM legal_entities WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $entity = $stmt->fetch();

    return $entity ?: null;
}

function generate_entity_hash(): string
{
    return bin2hex(random_bytes(16));
}

function create_entity(string $name, string $contactData): array
{
    $hash = generate_unique_entity_hash();

    $stmt = db()->prepare('INSERT INTO legal_entities (hash, name, contact_data) VALUES (:hash, :name, :contact_data)');
    $stmt->execute([
        ':hash' => $hash,
        ':name' => $name,
        ':contact_data' => $contactData,
    ]);

    return [
        'id' => (int) db()->lastInsertId(),
        'hash' => $hash,
    ];
}

function generate_unique_entity_hash(): string
{
    do {
        $hash = generate_entity_hash();
        $stmt = db()->prepare('SELECT COUNT(*) FROM legal_entities WHERE hash = :hash');
        $stmt->execute([':hash' => $hash]);
        $exists = (int) $stmt->fetchColumn() > 0;
    } while ($exists);

    return $hash;
}
