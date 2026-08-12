-- PostgreSQL 13+: move public identifiers to individual documents.
ALTER TABLE legal_documents ADD COLUMN public_hash VARCHAR(32);
UPDATE legal_documents
SET public_hash = replace(gen_random_uuid()::text, '-', '')
WHERE public_hash IS NULL;
ALTER TABLE legal_documents ALTER COLUMN public_hash SET NOT NULL;
ALTER TABLE legal_documents ADD CONSTRAINT legal_documents_public_hash UNIQUE (public_hash);
