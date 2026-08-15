-- Existing MySQL/MariaDB installations: allow accounts to use Passkeys exclusively.
ALTER TABLE users ADD COLUMN password_login_enabled BOOLEAN NOT NULL DEFAULT TRUE AFTER password_hash;
