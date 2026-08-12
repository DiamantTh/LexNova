-- MySQL 8 / MariaDB 10.10+: move public identifiers to individual documents.
ALTER TABLE legal_documents ADD COLUMN public_hash VARCHAR(32) NULL AFTER entity_id;
UPDATE legal_documents
SET public_hash = LOWER(HEX(RANDOM_BYTES(16)))
WHERE public_hash IS NULL;
ALTER TABLE legal_documents
    MODIFY public_hash VARCHAR(32) NOT NULL,
    ADD UNIQUE KEY legal_documents_public_hash (public_hash);
