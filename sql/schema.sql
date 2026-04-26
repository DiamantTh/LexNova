-- SQLite-focused schema. For MySQL, replace "INTEGER PRIMARY KEY AUTOINCREMENT" with "INT AUTO_INCREMENT PRIMARY KEY".

CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username VARCHAR(255) NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL
);

-- Each user may enroll multiple TOTP keys (e.g. phone + backup device).
-- Only keys with is_active = 1 are accepted during verification.
CREATE TABLE user_totp_keys (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    label       VARCHAR(100) NOT NULL DEFAULT 'Default',
    secret_enc  TEXT NOT NULL,          -- libsodium XSalsa20-Poly1305 ciphertext (hex)
    is_active   INTEGER NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL,
    last_used_at DATETIME DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE legal_entities (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    hash VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    contact_data TEXT NOT NULL
);

CREATE TABLE legal_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_id INTEGER NOT NULL,
    type VARCHAR(20) NOT NULL,
    language VARCHAR(20) NOT NULL,
    content TEXT NOT NULL,
    version VARCHAR(50) NOT NULL,
    updated_at DATETIME NOT NULL,
    FOREIGN KEY (entity_id) REFERENCES legal_entities(id)
);

-- Login / TOTP brute-force protection.
-- One row per (ip, endpoint) window.  Pruned on every successful request.
CREATE TABLE login_attempts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    ip         VARCHAR(45) NOT NULL,
    endpoint   VARCHAR(50) NOT NULL,   -- 'login' | 'totp_verify'
    attempts   INTEGER NOT NULL DEFAULT 1,
    blocked_until DATETIME DEFAULT NULL,
    last_at    DATETIME NOT NULL
);
CREATE UNIQUE INDEX login_attempts_ip_endpoint ON login_attempts (ip, endpoint);

-- Audit log: every sensitive write action is recorded here.
CREATE TABLE audit_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_id   INTEGER DEFAULT NULL,   -- NULL = CLI / system
    actor_name VARCHAR(255) DEFAULT NULL,
    action     VARCHAR(100) NOT NULL,
    target     VARCHAR(255) DEFAULT NULL,
    detail     TEXT DEFAULT NULL,
    ip         VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL
);

