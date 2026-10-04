ALTER TABLE users ADD COLUMN mfa_required BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE users ADD COLUMN activation_required BOOLEAN NOT NULL DEFAULT FALSE;

CREATE TABLE IF NOT EXISTS user_authenticators (
    id BIGSERIAL PRIMARY KEY, user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    kind VARCHAR(20) NOT NULL CHECK (kind IN ('webauthn', 'totp')),
    credential_id VARCHAR(1024) UNIQUE, credential_data TEXT, secret_enc TEXT,
    label VARCHAR(100) NOT NULL DEFAULT 'Authenticator', is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL, last_used_at TIMESTAMP NULL,
    CHECK ((kind = 'webauthn' AND credential_id IS NOT NULL AND credential_data IS NOT NULL AND secret_enc IS NULL)
        OR (kind = 'totp' AND credential_id IS NULL AND credential_data IS NULL AND secret_enc IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS user_authenticators_user_kind ON user_authenticators (user_id, kind);

INSERT INTO user_authenticators (user_id, kind, credential_id, credential_data, label, is_active, created_at, last_used_at)
SELECT user_id, 'webauthn', credential_id, credential_data, label, TRUE, created_at, last_used_at
FROM user_webauthn_credentials ON CONFLICT (credential_id) DO NOTHING;
INSERT INTO user_authenticators (user_id, kind, secret_enc, label, is_active, created_at, last_used_at)
SELECT old.user_id, 'totp', old.secret_enc, old.label, old.is_active, old.created_at, old.last_used_at
FROM user_totp_keys old
WHERE NOT EXISTS (
    SELECT 1 FROM user_authenticators current
    WHERE current.kind = 'totp' AND current.user_id = old.user_id
      AND current.secret_enc = old.secret_enc AND current.created_at = old.created_at
);
UPDATE users SET mfa_required = TRUE
WHERE EXISTS (SELECT 1 FROM user_authenticators a WHERE a.user_id = users.id);

CREATE TABLE IF NOT EXISTS user_sessions (
    id BIGSERIAL PRIMARY KEY, user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    session_hash CHAR(64) NOT NULL UNIQUE, auth_method VARCHAR(20) NOT NULL, auth_strength VARCHAR(20) NOT NULL,
    created_at TIMESTAMP NOT NULL, last_activity_at TIMESTAMP NOT NULL,
    absolute_expires_at TIMESTAMP NOT NULL, revoked_at TIMESTAMP NULL
);
CREATE INDEX IF NOT EXISTS user_sessions_user_active ON user_sessions (user_id, revoked_at);
CREATE TABLE IF NOT EXISTS user_activation_tickets (
    id BIGSERIAL PRIMARY KEY, user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash CHAR(64) NOT NULL UNIQUE, purpose VARCHAR(20) NOT NULL DEFAULT 'activation',
    created_by_user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP NOT NULL, expires_at TIMESTAMP NOT NULL,
    consumed_at TIMESTAMP NULL, revoked_at TIMESTAMP NULL
);
CREATE INDEX IF NOT EXISTS user_activation_tickets_user_active ON user_activation_tickets (user_id, consumed_at, revoked_at);

CREATE TABLE IF NOT EXISTS rate_limit_buckets (
    id BIGSERIAL PRIMARY KEY, ip VARCHAR(45) NOT NULL, endpoint VARCHAR(50) NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 1, blocked_until TIMESTAMP NULL, last_at TIMESTAMP NOT NULL,
    UNIQUE (ip, endpoint)
);
INSERT INTO rate_limit_buckets (id, ip, endpoint, attempts, blocked_until, last_at)
SELECT id, ip, endpoint, attempts, blocked_until, last_at FROM login_attempts ON CONFLICT (ip, endpoint) DO NOTHING;

CREATE TABLE IF NOT EXISTS audit_events (
    id BIGSERIAL PRIMARY KEY, actor_user_id BIGINT NULL, effective_user_id BIGINT NULL,
    actor_name VARCHAR(255) NULL, action VARCHAR(100) NOT NULL, target VARCHAR(255) NULL,
    detail TEXT NULL, ip VARCHAR(45) NULL, created_at TIMESTAMP NOT NULL
);
INSERT INTO audit_events (id, actor_user_id, actor_name, action, target, detail, ip, created_at)
SELECT id, actor_id, actor_name, action, target, detail, ip, created_at FROM audit_log ON CONFLICT (id) DO NOTHING;
SELECT setval(pg_get_serial_sequence('rate_limit_buckets', 'id'), COALESCE((SELECT MAX(id) FROM rate_limit_buckets), 1));
SELECT setval(pg_get_serial_sequence('audit_events', 'id'), COALESCE((SELECT MAX(id) FROM audit_events), 1));

DROP TABLE user_webauthn_credentials;
DROP TABLE user_totp_keys;
DROP TABLE login_attempts;
DROP TABLE audit_log;
