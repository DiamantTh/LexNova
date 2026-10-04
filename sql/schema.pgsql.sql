CREATE TABLE users (
    id BIGSERIAL PRIMARY KEY, username VARCHAR(255) NOT NULL UNIQUE,
    password_hash TEXT NOT NULL, password_login_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    mfa_required BOOLEAN NOT NULL DEFAULT FALSE,
    activation_required BOOLEAN NOT NULL DEFAULT FALSE,
    role VARCHAR(20) NOT NULL, created_at TIMESTAMP NOT NULL
);

CREATE TABLE user_authenticators (
    id BIGSERIAL PRIMARY KEY, user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    kind VARCHAR(20) NOT NULL CHECK (kind IN ('webauthn', 'totp')),
    credential_id VARCHAR(1024) UNIQUE, credential_data TEXT, secret_enc TEXT,
    label VARCHAR(100) NOT NULL DEFAULT 'Authenticator', is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL, last_used_at TIMESTAMP NULL,
    CHECK ((kind = 'webauthn' AND credential_id IS NOT NULL AND credential_data IS NOT NULL AND secret_enc IS NULL)
        OR (kind = 'totp' AND credential_id IS NULL AND credential_data IS NULL AND secret_enc IS NOT NULL))
);
CREATE INDEX user_authenticators_user_kind ON user_authenticators (user_id, kind);

CREATE TABLE user_sessions (
    id BIGSERIAL PRIMARY KEY, user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    session_hash CHAR(64) NOT NULL UNIQUE, auth_method VARCHAR(20) NOT NULL, auth_strength VARCHAR(20) NOT NULL,
    created_at TIMESTAMP NOT NULL, last_activity_at TIMESTAMP NOT NULL,
    absolute_expires_at TIMESTAMP NOT NULL, revoked_at TIMESTAMP NULL
);
CREATE INDEX user_sessions_user_active ON user_sessions (user_id, revoked_at);

CREATE TABLE user_activation_tickets (
    id BIGSERIAL PRIMARY KEY, user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash CHAR(64) NOT NULL UNIQUE, purpose VARCHAR(20) NOT NULL DEFAULT 'activation',
    created_by_user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP NOT NULL, expires_at TIMESTAMP NOT NULL,
    consumed_at TIMESTAMP NULL, revoked_at TIMESTAMP NULL
);
CREATE INDEX user_activation_tickets_user_active ON user_activation_tickets (user_id, consumed_at, revoked_at);

CREATE TABLE legal_entities (
    id BIGSERIAL PRIMARY KEY, hash VARCHAR(64) NOT NULL UNIQUE, name VARCHAR(255) NOT NULL, contact_data TEXT NOT NULL
);

CREATE TABLE legal_documents (
    id BIGSERIAL PRIMARY KEY, entity_id BIGINT NOT NULL REFERENCES legal_entities(id) ON DELETE CASCADE,
    public_hash VARCHAR(32) NOT NULL UNIQUE, type VARCHAR(20) NOT NULL,
    language VARCHAR(20) NOT NULL, content TEXT NOT NULL,
    version VARCHAR(50) NOT NULL, updated_at TIMESTAMP NOT NULL
);

CREATE TABLE rate_limit_buckets (
    id BIGSERIAL PRIMARY KEY, ip VARCHAR(45) NOT NULL, endpoint VARCHAR(50) NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 1, blocked_until TIMESTAMP NULL, last_at TIMESTAMP NOT NULL,
    UNIQUE (ip, endpoint)
);

CREATE TABLE audit_events (
    id BIGSERIAL PRIMARY KEY, actor_user_id BIGINT NULL, effective_user_id BIGINT NULL, actor_name VARCHAR(255) NULL,
    action VARCHAR(100) NOT NULL, target VARCHAR(255) NULL, detail TEXT NULL,
    ip VARCHAR(45) NULL, created_at TIMESTAMP NOT NULL
);

CREATE TABLE system_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL
);
