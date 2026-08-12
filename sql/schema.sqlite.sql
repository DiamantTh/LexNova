-- SQLite schema. Enable foreign keys for every PDO connection.

CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username VARCHAR(255) NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL
);

CREATE TABLE user_totp_keys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    label VARCHAR(100) NOT NULL DEFAULT 'Default',
    secret_enc TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    last_used_at DATETIME DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE user_webauthn_credentials (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    credential_id VARCHAR(1024) NOT NULL UNIQUE,
    credential_data TEXT NOT NULL,
    label VARCHAR(100) NOT NULL DEFAULT 'Passkey',
    created_at DATETIME NOT NULL,
    last_used_at DATETIME DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX user_webauthn_credentials_user_id ON user_webauthn_credentials (user_id);

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

CREATE TABLE login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip VARCHAR(45) NOT NULL,
    endpoint VARCHAR(50) NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 1,
    blocked_until DATETIME DEFAULT NULL,
    last_at DATETIME NOT NULL
);
CREATE UNIQUE INDEX login_attempts_ip_endpoint ON login_attempts (ip, endpoint);

CREATE TABLE audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_id INTEGER DEFAULT NULL,
    actor_name VARCHAR(255) DEFAULT NULL,
    action VARCHAR(100) NOT NULL,
    target VARCHAR(255) DEFAULT NULL,
    detail TEXT DEFAULT NULL,
    ip VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL
);
