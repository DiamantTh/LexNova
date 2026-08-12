CREATE TABLE user_webauthn_credentials (
    id BIGSERIAL PRIMARY KEY, user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    credential_id VARCHAR(1024) NOT NULL UNIQUE, credential_data TEXT NOT NULL,
    label VARCHAR(100) NOT NULL DEFAULT 'Passkey', created_at TIMESTAMP NOT NULL, last_used_at TIMESTAMP NULL
);
CREATE INDEX user_webauthn_credentials_user_id ON user_webauthn_credentials (user_id);
