# LexNova Core

## Voraussetzungen

**Pflicht:**
- PHP 8.4+
- PHP-Extensions: `sodium`, `pdo`, `json`, `mbstring`, `openssl`
- PDO-Treiber: `pdo_sqlite`, `pdo_mysql` oder `pdo_pgsql`
- Relationale SQL-Datenbank (SQLite, MariaDB, PostgreSQL)
- libsodium (`sodium` ist seit PHP 7.2 standardmäßig enthalten)

**Empfohlen:**
- PHP-Extension `intl` (für striktere BCP 47-Sprachcode-Validierung)
- Schreibzugriff auf `cache/` und `logs/` (für Twig-Cache und Logging)

Der Installer prüft alle Voraussetzungen automatisch und blockiert den Fortschritt bei fehlenden Pflicht-Extensions.

## Installation

1. Installer aufrufen: `/install`
2. Im ersten Schritt zeigt der Installer eine **Systemvoraussetzungen**-Prüfung:
   - Grün ✓ — Voraussetzung erfüllt
   - Rot ✗ — Pflichtvoraussetzung fehlt (Installation blockiert)
   - Orange ⚠ — Empfehlung fehlt (Installation möglich)
3. Formular ausfüllen:
   - Install-Passwort (wird in `data/install.pw` einmalig hinterlegt)
   - Datenbankverbindung (SQLite-Pfad oder Host/Name/User/Passwort)
   - Admin-Benutzername + Passwort
   - Standard-Sprache (BCP 47, z. B. `de`, `en-US`)
   - **Betreiber-Entity**: Name und Kontaktdaten der betreibenden Organisation
4. Nach erfolgreicher Installation:
   - `data/install.lock` wird erstellt — Installer ist danach gesperrt
   - `configs/config.toml` enthält die Konfiguration inkl. `totp_app_key`
   - Die öffentlichen URLs für Impressum und Datenschutzerklärung der Betreiber-Entity
     werden direkt angezeigt (z. B. `/{hash}/imprint`, `/{hash}/privacy`)
   - `data/install.pw` kann nach der Installation entfernt werden

> **Hinweis für frische Klone:** Fehlt `vendor/`, antwortet die Anwendung mit HTTP 503
> und einem Hinweis, dass zuerst `composer install` ausgeführt werden muss.

## Konfiguration

- Vorlage: `config.example.toml`
- Installiert: `configs/config.toml` (wird vom Installer erstellt)
- Sicherheitseinstellungen: `configs/security.toml` (im Repository enthalten)

Wichtige Abschnitte in `config.example.toml`:

| Abschnitt | Inhalt |
|---|---|
| `[database]` | Datenbankverbindung (DSN, User, Passwort) |
| `[security]` | `totp_app_key` (32 Byte hex, beim Install generiert) |
| `[rate_limit]` | `max_attempts`, `block_seconds` für Login-Brute-Force-Schutz |
| `[twig]` | `cache = true` aktiviert Template-Cache (empfohlen für Produktion) |

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
  - Passwortqualität wird beim Setzen mit zxcvbn bewertet (Score 0–4)
- TOTP Zwei-Faktor-Authentifizierung (SHA-256, 8-stellig, 30-Sekunden-Fenster)
  - Mehrere TOTP-Keys pro Benutzer möglich (z. B. Smartphone + YubiKey)
  - QR-Code bei der Einrichtung als SVG inline gerendert
  - Empfohlene Apps: Aegis, andOTP, Authy, Raivo (kein Google Authenticator)
- Rate Limiting: Login und TOTP-Versuche werden nach konfigurierbarer Anzahl
  für eine konfigurierbare Zeitspanne gesperrt (IP-basiert)

### Entities (Rechtliche Einheiten)

- Anlegen, Bearbeiten, Löschen
- Kontaktdaten als Freitext (mehrzeilig, je Zeile ein Adressbestandteil)
- Jede Entity erhält einen zufälligen 32-Zeichen-Hex-Hash für die öffentlichen URLs
- Die Betreiber-Entity wird automatisch beim Install angelegt

### Dokumente

- Anlegen, Bearbeiten, Löschen
- Typen: `imprint` (Impressum), `privacy` (Datenschutzerklärung)
- Mehrsprachig: pro Dokument ein BCP 47-Sprachcode (z. B. `de`, `en`, `fr-CH`)
- Versionierung (freies Versionsfeld, z. B. `2024-01`, `v3`)
- Direkt-Link „Anzeigen" öffnet die öffentliche URL im neuen Tab

### Benutzer

- Anlegen, Rolle ändern, Passwort setzen, Löschen
- TOTP-Keys verwalten (einzelne Keys löschen oder alle zurücksetzen)

### Audit-Log

- Die letzten 50 Admin-Aktionen werden im Dashboard angezeigt
- Erfasst: Zeitpunkt, Akteur, Aktion, Ziel, Detail, IP-Adresse

## Öffentliche URLs

```
/{hash}/{imprint|privacy}           Neueste Version (automatische Sprachauswahl)
/{hash}/{imprint|privacy}/{lang}    Neueste Version in der angegebenen Sprache
```

Beispiel: `/abc123def456.../imprint/de` oder `/abc123def456.../privacy/en`

### SEO und Caching

- Jede öffentliche Seite enthält:
  - `<link rel="canonical">` auf die sprachspezifische URL
  - `<link rel="alternate" hreflang="...">` für jede verfügbare Sprachversion
  - Sprachumschalter-Navigation (nur bei mehreren Sprachversionen sichtbar)
- HTTP-Header auf öffentlichen Dokumenten:
  - `Cache-Control: public, max-age=3600, stale-while-revalidate=86400`
  - 404-Antworten: `Cache-Control: no-store`
- Admin- und Installer-Seiten: `<meta name="robots" content="noindex, nofollow">`

## Datenbankmigrationen

Bestehende Installationen von der alten Single-TOTP-Architektur migrieren:

```
sql/migrations/001_multi_totp_keys.sql
```

Erfordert SQLite ≥ 3.35.0 (für `DROP COLUMN`).

## Abhängigkeiten (Packagist)

### Laufzeit (`require`)

| Paket | Zweck |
|---|---|
| `bjeavons/zxcvbn-php` | Passwortqualitätsbewertung (Score 0–4) beim Setzen von Passwörtern |
| `devium/toml` | TOML-Parser für `config.toml` und `security.toml` |
| `doctrine/dbal` | Datenbankabstraktion (SQLite, MariaDB, PostgreSQL) |
| `endroid/qr-code` | QR-Code-Generierung (SVG) bei TOTP-Einrichtung |
| `laminas/laminas-diactoros` | PSR-7 HTTP Message Implementierung |
| `laminas/laminas-filter` | Filter-Chain (StringTrim, Callback) für Input-Validierung |
| `laminas/laminas-i18n` | Internationalisierung (wird von laminas-validator benötigt) |
| `laminas/laminas-inputfilter` | Formular-Validierungs-Framework |
| `laminas/laminas-validator` | Einzelne Validatoren (NotEmpty, StringLength, InArray, Callback) |
| `mezzio/mezzio` | PSR-15 Middleware-Framework |
| `mezzio/mezzio-csrf` | CSRF-Token-Schutz für alle Formulare |
| `mezzio/mezzio-fastroute` | FastRoute-Adapter für Mezzio |
| `mezzio/mezzio-session` | Session-Middleware |
| `mezzio/mezzio-session-ext` | PHP-native Session-Implementierung |
| `mezzio/mezzio-twigrenderer` | Twig-Template-Renderer für Mezzio |
| `monolog/monolog` | Logging (Datei-Handler) |
| `php-di/php-di` | Dependency-Injection-Container |
| `psr/clock` | PSR-20 Clock-Interface (für testbare Zeitstempel) |
| `psr/simple-cache` | PSR-16 Simple Cache Interface |
| `spomky-labs/otphp` | TOTP/HOTP-Implementierung (RFC 6238) |
| `symfony/cache` | PSR-16-kompatible Cache-Implementierung (für Rate Limiting) |
| `symfony/console` | CLI-Framework für `bin/lexnova`-Befehle |
| `twig/twig` | Template-Engine |
| `web-auth/webauthn-lib` | WebAuthn/FIDO2 — **noch nicht implementiert**, als zukünftige Passkey-Unterstützung vorgehalten |

### Entwicklung (`require-dev`)

| Paket | Zweck |
|---|---|
| `friendsofphp/php-cs-fixer` | Code-Style-Prüfung und -Formatierung (PSR-12 + Symfony-Preset) |
| `phpstan/phpstan` | Statische Analyse, Level 6 |

### QA-Skripte

```
composer analyse       PHPStan-Analyse (Level 6, --memory-limit=512M)
composer cs-check      PHP-CS-Fixer Dry-Run (nur prüfen)
composer cs-fix        PHP-CS-Fixer mit automatischer Korrektur
composer qa            analyse + cs-check
```

Für PHP 8.5-dev: `PHP_CS_FIXER_IGNORE_ENV=1 composer cs-fix`

## Hinweise

- Dokumente werden als Freitext gespeichert (kein erzwungenes Format).
- Passwörter werden mit Argon2id gehasht (Parameter in `configs/security.toml`).
- TOTP-Secrets werden mit XSalsa20-Poly1305 (libsodium) verschlüsselt gespeichert.
- Admin-Zugang ist vor der Installation vollständig gesperrt (`InstalledCheckMiddleware`).
- CSRF-Schutz ist auf allen Formularen aktiv.
- Zeilenenden in Kontaktdaten und Dokumentinhalten werden serverseitig auf LF normalisiert (Windows-`\r\n` → `\n`).

