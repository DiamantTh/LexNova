ALTER TABLE users ADD COLUMN mfa_required BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE users ADD COLUMN activation_required BOOLEAN NOT NULL DEFAULT FALSE;

CREATE TABLE IF NOT EXISTS user_authenticators (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL, kind VARCHAR(20) NOT NULL,
    credential_id VARCHAR(1024) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE,
    credential_data LONGTEXT NULL, secret_enc TEXT NULL,
    label VARCHAR(100) NOT NULL DEFAULT 'Authenticator', is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME NOT NULL, last_used_at DATETIME NULL,
    CONSTRAINT chk_authenticator_kind CHECK (kind IN ('webauthn', 'totp')),
    CONSTRAINT chk_authenticator_data CHECK ((kind = 'webauthn' AND credential_id IS NOT NULL AND credential_data IS NOT NULL AND secret_enc IS NULL)
        OR (kind = 'totp' AND credential_id IS NULL AND credential_data IS NULL AND secret_enc IS NOT NULL)),
    CONSTRAINT fk_authenticator_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX user_authenticators_user_kind (user_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO user_authenticators
    (user_id, kind, credential_id, credential_data, label, is_active, created_at, last_used_at)
SELECT user_id, 'webauthn', credential_id, credential_data, label, TRUE, created_at, last_used_at
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
UPDATE users SET mfa_required = TRUE
WHERE EXISTS (SELECT 1 FROM user_authenticators a WHERE a.user_id = users.id);

CREATE TABLE IF NOT EXISTS user_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL, session_hash CHAR(64) NOT NULL UNIQUE,
    auth_method VARCHAR(20) NOT NULL, auth_strength VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL, last_activity_at DATETIME NOT NULL,
    absolute_expires_at DATETIME NOT NULL, revoked_at DATETIME NULL,
    CONSTRAINT fk_session_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX user_sessions_user_active (user_id, revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS user_activation_tickets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE,
    purpose VARCHAR(20) NOT NULL DEFAULT 'activation',
    created_by_user_id BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL, consumed_at DATETIME NULL, revoked_at DATETIME NULL,
    CONSTRAINT fk_activation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_activation_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX user_activation_tickets_user_active (user_id, consumed_at, revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limit_buckets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(45) NOT NULL,
    endpoint VARCHAR(50) NOT NULL, attempts INT NOT NULL DEFAULT 1,
    blocked_until DATETIME NULL, last_at DATETIME NOT NULL, UNIQUE KEY rate_limit_buckets_ip_endpoint (ip, endpoint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO rate_limit_buckets (id, ip, endpoint, attempts, blocked_until, last_at)
SELECT id, ip, endpoint, attempts, blocked_until, last_at FROM login_attempts;

CREATE TABLE IF NOT EXISTS audit_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, actor_user_id BIGINT UNSIGNED NULL,
    effective_user_id BIGINT UNSIGNED NULL, actor_name VARCHAR(255) NULL,
    action VARCHAR(100) NOT NULL, target VARCHAR(255) NULL, detail TEXT NULL,
    ip VARCHAR(45) NULL, created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO audit_events (id, actor_user_id, actor_name, action, target, detail, ip, created_at)
SELECT id, actor_id, actor_name, action, target, detail, ip, created_at FROM audit_log;

DROP TABLE user_webauthn_credentials;
DROP TABLE user_totp_keys;
DROP TABLE login_attempts;
DROP TABLE audit_log;
