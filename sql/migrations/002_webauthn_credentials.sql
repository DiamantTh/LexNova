-- Existing SQLite installations: adds persistent WebAuthn/Passkey credentials.
CREATE TABLE user_webauthn_credentials (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    credential_id VARCHAR(1024) NOT NULL UNIQUE,
    credential_data TEXT NOT NULL,
    label VARCHAR(100) NOT NULL DEFAULT 'Passkey',
    created_at DATETIME NOT NULL,
    last_used_at DATETIME DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX user_webauthn_credentials_user_id ON user_webauthn_credentials (user_id);
