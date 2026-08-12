-- SQLite: move public identifiers from entities to individual documents.
ALTER TABLE legal_documents ADD COLUMN public_hash VARCHAR(32) DEFAULT NULL;
UPDATE legal_documents
SET public_hash = lower(hex(randomblob(16)))
WHERE public_hash IS NULL;
CREATE UNIQUE INDEX legal_documents_public_hash ON legal_documents (public_hash);
