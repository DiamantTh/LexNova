# LexNova Core

## Voraussetzungen
- PHP 8.4+
- Relationale SQL-Datenbank (SQLite, MariaDB, PostgreSQL)
- libsodium (PHP-Extension `sodium`, standardmäßig in PHP 8.x enthalten)

## Installation
1. Installer aufrufen: `/install`
2. Im Installer folgende Daten eingeben:
   - Install-Passwort (wird automatisch erzeugt und einmalig angezeigt)
   - Datenbankverbindung (SQLite-Pfad oder Host/Name/User/Passwort)
   - Admin-Benutzername + Passwort
   - Standard-Sprache (BCP 47, z. B. `de`, `en-US`)
3. Nach erfolgreicher Installation:
   - `data/install.lock` wird erstellt
   - `configs/config.toml` enthält die Konfiguration inkl. `totp_app_key`
   - `data/install.pw` kann entfernt werden

## Konfiguration
- Vorlage: `config.example.toml`
- Installiert: `configs/config.toml` (wird vom Installer erstellt)
- Sicherheitseinstellungen: `configs/security.toml` (im Repository enthalten)

Wichtige Abschnitte in `config.example.toml`:
- `[database]` — Datenbankverbindung
- `[security]` — `totp_app_key` (32 Byte hex, wird beim Install generiert)
- `[rate_limit]` — `max_attempts`, `block_seconds` für Login-Brute-Force-Schutz
- `[twig]` — Template-Cache aktivieren (`cache = true`)

## CLI
```
bin/lexnova entity:list                         Alle Entities auflisten
bin/lexnova user:create <username>              Neuen Admin-User anlegen
bin/lexnova user:delete <username> [-y]         Admin-User löschen
bin/lexnova user:list                           Alle User auflisten (inkl. TOTP-Status)
bin/lexnova user:set-password <username>        Passwort zurücksetzen
bin/lexnova user:totp-reset <username> [-y]     Alle TOTP-Keys eines Users löschen
```

## Admin-Bereich (`/admin`)

### Authentifizierung
- Login mit Benutzername + Passwort
- TOTP Zwei-Faktor-Authentifizierung (SHA-256, 8-stellig, 30-Sekunden-Fenster)
  - Mehrere TOTP-Keys pro Benutzer möglich (z. B. Smartphone + YubiKey)
  - Empfohlene Apps: Aegis, andOTP, Authy, Raivo (kein Google Authenticator)
- Rate Limiting: Login-Versuche werden nach konfigurierbarer Anzahl für eine konfigurierbare Zeitspanne gesperrt

### Entities (Rechtliche Einheiten)
- Anlegen, Bearbeiten, Löschen
- Jede Entity erhält einen eindeutigen Hash für die öffentlichen URLs

### Dokumente
- Anlegen, Bearbeiten, Löschen
- Typen: `imprint` (Impressum), `privacy` (Datenschutzerklärung)
- Mehrsprachig (BCP 47-Sprachcode pro Dokument)
- Versionierung (freies Versionsfeld)

### Benutzer
- Anlegen, Rolle ändern, Passwort setzen, Löschen
- TOTP-Keys verwalten (einzelne Keys löschen oder alle zurücksetzen)

### Audit-Log
- Die letzten 50 Admin-Aktionen werden im Dashboard angezeigt
- Erfasst: Zeitpunkt, Akteur, Aktion, Ziel, Detail, IP-Adresse

## Öffentliche URLs

```
/{hash}/{imprint|privacy}           Neueste Version (Standard-Sprache)
/{hash}/{imprint|privacy}/{lang}    Neueste Version in der angegebenen Sprache
```

Beispiel: `/abc123def456.../imprint` oder `/abc123def456.../privacy/de`

## Datenbankmigrationen

Bestehende Installationen von der alten Single-TOTP-Architektur migrieren:
```
sql/migrations/001_multi_totp_keys.sql
```
Erfordert SQLite ≥ 3.35.0 (für `DROP COLUMN`).

## Hinweise
- Dokumente werden als Freitext (Markdown oder HTML) gespeichert.
- Passwörter werden mit Argon2id gehasht (Konfiguration in `configs/security.toml`).
- TOTP-Secrets werden mit XSalsa20-Poly1305 (libsodium) verschlüsselt gespeichert.
- Admin-Zugang ist vor der Installation gesperrt.
- CSRF-Schutz ist auf allen Formularen aktiv.
