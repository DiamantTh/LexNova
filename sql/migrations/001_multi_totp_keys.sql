-- Migration 001: Multi-TOTP-Keys + Rate-Limiting + Audit-Log
-- Applies to installations created with the old schema that had
-- totp_secret TEXT and totp_enabled INTEGER columns on the users table.
--
-- Run order: apply all migrations in numeric order before starting the app.
-- SQLite does not support DROP COLUMN in versions < 3.35.0 (2021-03-12);
-- if your SQLite version is older, use the CREATE NEW TABLE strategy below
-- instead of the ALTER TABLE DROP COLUMN statements.
--
-- ── Check your SQLite version first: ─────────────────────────────────────────
--   SELECT sqlite_version();
-- ─────────────────────────────────────────────────────────────────────────────

-- ── Step 1: Move existing TOTP secrets to the new table ───────────────────────
-- (Only needed if users already enrolled TOTP under the old schema.)
-- The old encrypted secret is migrated to user_totp_keys with label 'Default'.
CREATE TABLE IF NOT EXISTS user_totp_keys (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    label        VARCHAR(100) NOT NULL DEFAULT 'Default',
    secret_enc   TEXT NOT NULL,
    is_active    INTEGER NOT NULL DEFAULT 1,
    created_at   DATETIME NOT NULL,
    last_used_at DATETIME DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

INSERT INTO user_totp_keys (user_id, label, secret_enc, is_active, created_at)
SELECT id, 'Default', totp_secret, totp_enabled, datetime('now')
FROM   users
WHERE  totp_secret IS NOT NULL
  AND  totp_secret <> '';

-- ── Step 2: Remove old TOTP columns from users ────────────────────────────────
-- Requires SQLite >= 3.35.0. If you get "no such column" errors, the column
-- may already have been dropped — skip these two statements.
ALTER TABLE users DROP COLUMN totp_secret;
ALTER TABLE users DROP COLUMN totp_enabled;

-- ── Step 3: Brute-force protection table ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS login_attempts (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    ip            VARCHAR(45) NOT NULL,
    endpoint      VARCHAR(50) NOT NULL,
    attempts      INTEGER NOT NULL DEFAULT 1,
    blocked_until DATETIME DEFAULT NULL,
    last_at       DATETIME NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS login_attempts_ip_endpoint
    ON login_attempts (ip, endpoint);

-- ── Step 4: Audit log table ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS audit_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_id   INTEGER DEFAULT NULL,
    actor_name VARCHAR(255) DEFAULT NULL,
    action     VARCHAR(100) NOT NULL,
    target     VARCHAR(255) DEFAULT NULL,
    detail     TEXT DEFAULT NULL,
    ip         VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL
);
