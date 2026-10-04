-- Consolidate authentication data while preserving credential bytes and metadata.
ALTER TABLE users ADD COLUMN mfa_required INTEGER NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN activation_required INTEGER NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS user_authenticators (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    kind VARCHAR(20) NOT NULL,
    credential_id VARCHAR(1024) DEFAULT NULL UNIQUE,
    credential_data TEXT DEFAULT NULL,
    secret_enc TEXT DEFAULT NULL,
    label VARCHAR(100) NOT NULL DEFAULT 'Authenticator',
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    last_used_at DATETIME DEFAULT NULL,
    CHECK (kind IN ('webauthn', 'totp')),
    CHECK ((kind = 'webauthn' AND credential_id IS NOT NULL AND credential_data IS NOT NULL AND secret_enc IS NULL)
        OR (kind = 'totp' AND credential_id IS NULL AND credential_data IS NULL AND secret_enc IS NOT NULL)),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS user_authenticators_user_kind ON user_authenticators (user_id, kind);

INSERT OR IGNORE INTO user_authenticators
    (user_id, kind, credential_id, credential_data, label, is_active, created_at, last_used_at)
SELECT user_id, 'webauthn', credential_id, credential_data, label, 1, created_at, last_used_at
FROM user_webauthn_credentials;
INSERT INTO user_authenticators
    (user_id, kind, secret_enc, label, is_active, created_at, last_used_at)
SELECT old.user_id, 'totp', old.secret_enc, old.label, old.is_active, old.created_at, old.last_used_at
FROM user_totp_keys old
WHERE NOT EXISTS (
    SELECT 1 FROM user_authenticators current
    WHERE current.kind = 'totp' AND current.user_id = old.user_id
      AND current.secret_enc = old.secret_enc AND current.created_at = old.created_at
);
UPDATE users SET mfa_required = 1
WHERE id IN (
    SELECT user_id FROM user_authenticators WHERE kind IN ('webauthn', 'totp')
);

CREATE TABLE IF NOT EXISTS user_sessions (
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
CREATE INDEX IF NOT EXISTS user_sessions_user_active ON user_sessions (user_id, revoked_at);
CREATE TABLE IF NOT EXISTS user_activation_tickets (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE,
    purpose VARCHAR(20) NOT NULL DEFAULT 'activation',
    created_by_user_id INTEGER DEFAULT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL,
    consumed_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS user_activation_tickets_user_active ON user_activation_tickets (user_id, consumed_at, revoked_at);

CREATE TABLE IF NOT EXISTS rate_limit_buckets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip VARCHAR(45) NOT NULL,
    endpoint VARCHAR(50) NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 1,
    blocked_until DATETIME DEFAULT NULL,
    last_at DATETIME NOT NULL,
    UNIQUE (ip, endpoint)
);
INSERT OR IGNORE INTO rate_limit_buckets (id, ip, endpoint, attempts, blocked_until, last_at)
SELECT id, ip, endpoint, attempts, blocked_until, last_at FROM login_attempts;

CREATE TABLE IF NOT EXISTS audit_events (
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
INSERT OR IGNORE INTO audit_events (id, actor_user_id, actor_name, action, target, detail, ip, created_at)
SELECT id, actor_id, actor_name, action, target, detail, ip, created_at FROM audit_log;

DROP TABLE user_webauthn_credentials;
DROP TABLE user_totp_keys;
DROP TABLE login_attempts;
DROP TABLE audit_log;
