CREATE TABLE user_webauthn_credentials (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL, credential_id VARCHAR(1024) NOT NULL UNIQUE,
    credential_data LONGTEXT NOT NULL, label VARCHAR(100) NOT NULL DEFAULT 'Passkey',
    created_at DATETIME NOT NULL, last_used_at DATETIME NULL,
    CONSTRAINT fk_webauthn_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX user_webauthn_credentials_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
