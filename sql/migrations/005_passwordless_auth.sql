-- Existing SQLite installations: allow accounts to use Passkeys exclusively.
ALTER TABLE users ADD COLUMN password_login_enabled INTEGER NOT NULL DEFAULT 1;
