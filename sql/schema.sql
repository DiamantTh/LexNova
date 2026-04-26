-- SQLite-focused schema. For MySQL, replace "INTEGER PRIMARY KEY AUTOINCREMENT" with "INT AUTO_INCREMENT PRIMARY KEY".

CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username VARCHAR(255) NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role VARCHAR(20) NOT NULL,
    totp_secret TEXT DEFAULT NULL,
    totp_enabled INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL
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
