CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    password_login_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    mfa_required BOOLEAN NOT NULL DEFAULT FALSE,
    activation_required BOOLEAN NOT NULL DEFAULT FALSE,
    role VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_authenticators (
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

CREATE TABLE user_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL, session_hash CHAR(64) NOT NULL UNIQUE,
    auth_method VARCHAR(20) NOT NULL, auth_strength VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL, last_activity_at DATETIME NOT NULL,
    absolute_expires_at DATETIME NOT NULL, revoked_at DATETIME NULL,
    CONSTRAINT fk_session_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX user_sessions_user_active (user_id, revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_activation_tickets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE,
    purpose VARCHAR(20) NOT NULL DEFAULT 'activation',
    created_by_user_id BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL, consumed_at DATETIME NULL, revoked_at DATETIME NULL,
    CONSTRAINT fk_activation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_activation_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX user_activation_tickets_user_active (user_id, consumed_at, revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE legal_entities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    hash VARCHAR(64) NOT NULL UNIQUE, name VARCHAR(255) NOT NULL, contact_data TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE legal_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity_id BIGINT UNSIGNED NOT NULL, public_hash VARCHAR(32) NOT NULL UNIQUE,
    type VARCHAR(20) NOT NULL, language VARCHAR(20) NOT NULL,
    content LONGTEXT NOT NULL, version VARCHAR(50) NOT NULL, updated_at DATETIME NOT NULL,
    CONSTRAINT fk_document_entity FOREIGN KEY (entity_id) REFERENCES legal_entities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limit_buckets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(45) NOT NULL,
    endpoint VARCHAR(50) NOT NULL, attempts INT NOT NULL DEFAULT 1, blocked_until DATETIME NULL, last_at DATETIME NOT NULL,
    UNIQUE KEY rate_limit_buckets_ip_endpoint (ip, endpoint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, actor_user_id BIGINT UNSIGNED NULL,
    effective_user_id BIGINT UNSIGNED NULL,
    actor_name VARCHAR(255) NULL, action VARCHAR(100) NOT NULL, target VARCHAR(255) NULL,
    detail TEXT NULL, ip VARCHAR(45) NULL, created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE system_settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
