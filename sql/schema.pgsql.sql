CREATE TABLE users (
    id BIGSERIAL PRIMARY KEY, username VARCHAR(255) NOT NULL UNIQUE,
    password_hash TEXT NOT NULL, role VARCHAR(20) NOT NULL, created_at TIMESTAMP NOT NULL
);

CREATE TABLE user_totp_keys (
    id BIGSERIAL PRIMARY KEY, user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    label VARCHAR(100) NOT NULL DEFAULT 'Default', secret_enc TEXT NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE, created_at TIMESTAMP NOT NULL, last_used_at TIMESTAMP NULL
);

CREATE TABLE user_webauthn_credentials (
    id BIGSERIAL PRIMARY KEY, user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    credential_id VARCHAR(1024) NOT NULL UNIQUE, credential_data TEXT NOT NULL,
    label VARCHAR(100) NOT NULL DEFAULT 'Passkey', created_at TIMESTAMP NOT NULL, last_used_at TIMESTAMP NULL
);
CREATE INDEX user_webauthn_credentials_user_id ON user_webauthn_credentials (user_id);

CREATE TABLE legal_entities (
    id BIGSERIAL PRIMARY KEY, hash VARCHAR(64) NOT NULL UNIQUE, name VARCHAR(255) NOT NULL, contact_data TEXT NOT NULL
);

CREATE TABLE legal_documents (
    id BIGSERIAL PRIMARY KEY, entity_id BIGINT NOT NULL REFERENCES legal_entities(id) ON DELETE CASCADE,
    public_hash VARCHAR(32) NOT NULL UNIQUE, type VARCHAR(20) NOT NULL,
    language VARCHAR(20) NOT NULL, content TEXT NOT NULL,
    version VARCHAR(50) NOT NULL, updated_at TIMESTAMP NOT NULL
);

CREATE TABLE login_attempts (
    id BIGSERIAL PRIMARY KEY, ip VARCHAR(45) NOT NULL, endpoint VARCHAR(50) NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 1, blocked_until TIMESTAMP NULL, last_at TIMESTAMP NOT NULL,
    UNIQUE (ip, endpoint)
);

CREATE TABLE audit_log (
    id BIGSERIAL PRIMARY KEY, actor_id BIGINT NULL, actor_name VARCHAR(255) NULL,
    action VARCHAR(100) NOT NULL, target VARCHAR(255) NULL, detail TEXT NULL,
    ip VARCHAR(45) NULL, created_at TIMESTAMP NOT NULL
);
