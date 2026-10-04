-- SQLite schema. Enable foreign keys for every PDO connection.

CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username VARCHAR(255) NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    password_login_enabled INTEGER NOT NULL DEFAULT 1,
    mfa_required INTEGER NOT NULL DEFAULT 0,
    activation_required INTEGER NOT NULL DEFAULT 0,
    role VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL
);

CREATE TABLE user_authenticators (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    kind VARCHAR(20) NOT NULL,
    credential_id VARCHAR(1024) DEFAULT NULL,
    credential_data TEXT DEFAULT NULL,
    secret_enc TEXT DEFAULT NULL,
    label VARCHAR(100) NOT NULL DEFAULT 'Authenticator',
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    last_used_at DATETIME DEFAULT NULL,
    UNIQUE (credential_id),
    CHECK (kind IN ('webauthn', 'totp')),
    CHECK ((kind = 'webauthn' AND credential_id IS NOT NULL AND credential_data IS NOT NULL AND secret_enc IS NULL)
        OR (kind = 'totp' AND credential_id IS NULL AND credential_data IS NULL AND secret_enc IS NOT NULL)),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX user_authenticators_user_kind ON user_authenticators (user_id, kind);

CREATE TABLE user_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    session_hash VARCHAR(64) NOT NULL UNIQUE,
    auth_method VARCHAR(20) NOT NULL,
    auth_strength VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL,
    last_activity_at DATETIME NOT NULL,
    absolute_expires_at DATETIME NOT NULL,
    revoked_at DATETIME DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX user_sessions_user_active ON user_sessions (user_id, revoked_at);

CREATE TABLE user_activation_tickets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    purpose VARCHAR(20) NOT NULL DEFAULT 'activation',
    created_by_user_id INTEGER DEFAULT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME DEFAULT NULL,
    revoked_at DATETIME DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX user_activation_tickets_user_active ON user_activation_tickets (user_id, consumed_at, revoked_at);

CREATE TABLE legal_entities (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    hash VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    contact_data TEXT NOT NULL
);

CREATE TABLE legal_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_id INTEGER NOT NULL,
    public_hash VARCHAR(32) NOT NULL UNIQUE,
    type VARCHAR(20) NOT NULL,
    language VARCHAR(20) NOT NULL,
    content TEXT NOT NULL,
    version VARCHAR(50) NOT NULL,
    updated_at DATETIME NOT NULL,
    FOREIGN KEY (entity_id) REFERENCES legal_entities(id) ON DELETE CASCADE
);

CREATE TABLE rate_limit_buckets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip VARCHAR(45) NOT NULL,
    endpoint VARCHAR(50) NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 1,
    blocked_until DATETIME DEFAULT NULL,
    last_at DATETIME NOT NULL
);
CREATE UNIQUE INDEX rate_limit_buckets_ip_endpoint ON rate_limit_buckets (ip, endpoint);

CREATE TABLE audit_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_user_id INTEGER DEFAULT NULL,
    effective_user_id INTEGER DEFAULT NULL,
    actor_name VARCHAR(255) DEFAULT NULL,
    action VARCHAR(100) NOT NULL,
    target VARCHAR(255) DEFAULT NULL,
    detail TEXT DEFAULT NULL,
    ip VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL
);

CREATE TABLE system_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at DATETIME NOT NULL
);
