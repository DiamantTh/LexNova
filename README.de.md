# LexNova Core

## Betrieb auf Shared Hosting

LexNova ist für klassischen PHP-Betrieb ohne Container und ohne Release-System
geeignet. Das **einzige** öffentliche Verzeichnis ist `httpdocs/`; der gesamte
Projektordner darf nicht als DocumentRoot konfiguriert werden. Dadurch bleiben
`config/`, `data/`, `src/`, `templates/`, `vendor/` und `var/`
außerhalb des Webzugriffs.

- Apache 2.4: Bei Shared Hosting ohne eigenen vHost greift
  `httpdocs/.htaccess`; bei eigener Serverkonfiguration muss `httpdocs/` der
  DocumentRoot sein.
- Nginx + PHP-FPM: Der Serveradmin benötigt folgende wesentliche Regeln:

  ```nginx
  root /var/www/lexnova/httpdocs;

  location / {
      try_files $uri $uri/ /index.php?$query_string;
  }

  location ~ \.php$ {
      try_files $uri =404;
      include fastcgi_params;
      fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
      fastcgi_pass unix:/run/php/php8.4-fpm.sock;
  }

  location ~ /\. { deny all; }
  ```

  Pfad und PHP-FPM-Socket sind hostabhängig.
- TLS wird vom Hoster eingerichtet. `app.base_url` muss genau der öffentlichen
  HTTPS-URL entsprechen, weil Session-Cookies und Passkeys an diese Origin
  gebunden sind.

## Voraussetzungen

**Pflicht:**
- PHP 8.4+
- PHP-Extensions: `sodium`, `pdo`, `json`, `mbstring`, `openssl`, `intl`
- PDO-Treiber: `pdo_sqlite`, `pdo_mysql` oder `pdo_pgsql`
- Relationale SQL-Datenbank (SQLite, MariaDB, PostgreSQL)
- libsodium (`sodium` ist seit PHP 7.2 standardmäßig enthalten)

**Zur Laufzeit:**
- Schreibzugriff auf `data/` (bei SQLite) und `var/` (Cache und Logging).
  Beide Verzeichnisse werden bei Bedarf automatisch angelegt und sind nicht
  Teil des Repositorys.

`config/config.toml` und `data/install.pw` werden vom Installer mit Modus
`0600` angelegt. Nach erfolgreicher Installation genügt Schreibzugriff für PHP
auf `data/` (bei SQLite) und `var/`; `config/` kann anschließend
wieder schreibgeschützt werden.

Der Installer prüft alle Voraussetzungen automatisch und blockiert den Fortschritt bei fehlenden Pflicht-Extensions.

## Installation

1. Projektordner oberhalb des Webroots ablegen und den DocumentRoot auf
   `httpdocs/` setzen.
2. Abhängigkeiten installieren (oder das auf derselben PHP-Version erzeugte
   `vendor/` hochladen):

   ```
   composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
   ```

3. Installer-Passwort vorbereiten. Mit Shell-Zugang:

   ```
   bin/lexnova install:prepare
   ```

   Ohne Shell `LEXNOVA_INSTALL_PASSWORD` im Hosting-Panel als geheime
   Umgebungsvariable setzen; der Installer übernimmt sie beim ersten Aufruf.
   Das Passwort wird absichtlich nie mehr über die Weboberfläche erzeugt oder
   angezeigt.
4. Installer aufrufen: `/install`
5. Im ersten Schritt zeigt der Installer eine **Systemvoraussetzungen**-Prüfung:
   - Grün ✓ — Voraussetzung erfüllt
   - Rot ✗ — Pflichtvoraussetzung fehlt (Installation blockiert)
   - Orange ⚠ — Empfehlung fehlt (Installation möglich)
6. Formular ausfüllen:
   - Install-Passwort (wird in `data/install.pw` einmalig hinterlegt)
   - Datenbankverbindung (SQLite-Pfad oder Host/Name/User/Passwort)
   - Admin-Benutzername + Passwort
   - Standard-Sprache (BCP 47, z. B. `de`, `en-US`)
   - **Betreiber-Entity**: Name und Kontaktdaten der betreibenden Organisation
7. Nach erfolgreicher Installation:
   - `data/install.lock` wird erstellt — Installer ist danach gesperrt
   - `config/config.toml` enthält die Konfiguration inkl. `totp_app_key`
   - Die öffentlichen URLs für Impressum und Datenschutzerklärung der Betreiber-Entity
     werden direkt angezeigt (z. B. `/{hash}/imprint`, `/{hash}/privacy`)
   - `data/install.pw` kann nach der Installation entfernt werden

> **Hinweis für frische Klone:** Fehlt `vendor/`, antwortet die Anwendung mit HTTP 503
> und einem Hinweis, dass zuerst `composer install` ausgeführt werden muss.

## Update ohne Releases

Ein einzelner Shared-Host kann LexNova direkt im Arbeitsverzeichnis aktualisieren;
ein separates Release-Verzeichnis ist nicht erforderlich. Vor jedem Update eine
Sicherung von Datenbank und `config/config.toml` erstellen. Dann:

1. Passende SQL-Migrationen aus `sql/migrations/` in numerischer Reihenfolge
   auf der produktiven Datenbank ausführen (vor der Code-Aktualisierung).
2. Code aktualisieren, beispielsweise `git pull --ff-only`.
3. Abhängigkeiten exakt aus dem Lockfile installieren:

   ```
   composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
   ```

4. PHP-FPM neu laden oder den Apache-PHP-Prozess neu starten, falls der Hoster
   OPcache ohne Zeitstempelprüfung betreibt.

Nie `composer update` auf dem Produktivsystem ausführen. Für Updates mit mehreren
gleichzeitigen Webprozessen oder ohne kurze Inkonsistenz während `git pull` ist ein
Release-Verzeichnis sinnvoll, aber für einen einzelnen Shared Host nicht nötig.

## Konfiguration

- Vorlage: `config/config.example.toml`
- Installiert: `config/config.toml` (wird vom Installer erstellt)
- Sicherheitseinstellungen: `config/security.toml` (im Repository enthalten)

Wichtige Abschnitte in `config/config.example.toml`:

| Abschnitt | Inhalt |
|---|---|
| `[db]` | Datenbankverbindung (DSN, User, Passwort) |
| `[security]` | `totp_app_key` (32 Byte hex, beim Install generiert) |
| `[security.rate_limit]` | `max_attempts`, `block_seconds` für Login-Brute-Force-Schutz |
| `[twig]` | `cache = true` aktiviert Template-Cache (empfohlen für Produktion) |
| `[cache]` | Dokument-Cache: standardmäßig Dateisystem; optional Valkey per Redis-DSN |

`[app].base_url` muss die öffentliche HTTPS-URL der Instanz enthalten. Sie ist
für sichere Session-Cookies und Passkeys erforderlich. Nur für lokale Entwicklung
ist `http://localhost` zulässig.

### Valkey

Valkey kann als verteilter Dokument-Cache genutzt werden, weil es zum Redis-Protokoll
kompatibel ist. In `config/config.toml`:

```
[cache]
adapter = "valkey"
dsn = "redis://127.0.0.1:6379/0"
namespace = "lexnova"
```

Ist Valkey nicht erreichbar, fällt LexNova kontrolliert auf den Dateisystem-Cache
zurück. Geänderte Dokumente invalidieren alle Sprachvarianten sofort.

## CLI

```
bin/lexnova entity:list                         Alle Entities auflisten
bin/lexnova install:prepare                     Einmaliges Installer-Passwort erzeugen
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
- Passkeys/WebAuthn: passwortloser Login über Plattform-Authenticator oder
  Sicherheitsschlüssel; Passkeys werden im Adminbereich registriert

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

Frische Installationen verwenden automatisch das passende aktuelle Schema für
SQLite, MySQL/MariaDB oder PostgreSQL. Die folgenden Migrationen sind nur für
ältere Installationen nötig:

```
sql/migrations/001_multi_totp_keys.sql                # nur SQLite, altes Single-TOTP-Schema
sql/migrations/002_webauthn_credentials.sql          # SQLite
sql/migrations/002_webauthn_credentials.mysql.sql    # MySQL/MariaDB
sql/migrations/002_webauthn_credentials.pgsql.sql    # PostgreSQL
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
| `symfony/cache` | PSR-16-kompatibler Dateisystem- oder optionaler Valkey-Cache für öffentliche Dokumente |
| `symfony/console` | CLI-Framework für `bin/lexnova`-Befehle |
| `twig/twig` | Template-Engine |
| `web-auth/webauthn-lib` | WebAuthn/FIDO2-Passkeys: Registrierung im Adminbereich und passwortloser Login |

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

## Hinweise

- Dokumente werden als Freitext gespeichert (kein erzwungenes Format).
- Passwörter werden mit Argon2id gehasht (Parameter in `config/security.toml`).
- TOTP-Secrets werden mit XSalsa20-Poly1305 (libsodium) verschlüsselt gespeichert.
- Admin-Zugang ist vor der Installation vollständig gesperrt (`InstalledCheckMiddleware`).
- CSRF-Schutz ist auf allen Formularen aktiv.
- Zeilenenden in Kontaktdaten und Dokumentinhalten werden serverseitig auf LF normalisiert (Windows-`\r\n` → `\n`).
