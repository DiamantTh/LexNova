# LexNova Core

[English](README.md) | **Deutsch**

LexNova verwaltet, versioniert und veröffentlicht Impressums- und
Datenschutztexte für persönliche Bereiche und Teams. Der verbindliche geplante
Umfang steht in [docs/PRODUCT_SCOPE.md](docs/PRODUCT_SCOPE.md), die geplante
Datenbankentwicklung in
[docs/DATABASE_EVOLUTION.md](docs/DATABASE_EVOLUTION.md) und die
Sicherheitsanforderungen in
[docs/SECURITY_BASELINE.md](docs/SECURITY_BASELINE.md).
Die optionale, für Shared Hosting ausgelegte Fail2ban-Signalausgabe ist in
[docs/FAIL2BAN.md](docs/FAIL2BAN.md) beschrieben.

Der derzeitige Admin-Prototyp bildet Rollen, Workspaces, Planlimits und echte
unveränderliche Dokumentrevisionen noch nicht vollständig ab. Bis diese Punkte
implementiert und migrierbar getestet sind, ist der Source als Vorabentwicklung
und nicht als stabile Produktivversion zu behandeln.

## Betrieb auf Shared Hosting

LexNova ist für klassischen PHP-Betrieb ohne Container und ohne Release-System
geeignet. Das **einzige** öffentliche Verzeichnis ist `httpdocs/`; der gesamte
Projektordner darf nicht als DocumentRoot konfiguriert werden. Dadurch bleiben
`config/`, `data/`, `src/`, `templates/`, `vendor/` und `var/`
außerhalb des Webzugriffs.

- Apache 2.4: Bei Shared Hosting ohne eigenen vHost greift
  `httpdocs/.htaccess`; bei eigener Serverkonfiguration muss `httpdocs/` der
  DocumentRoot sein und `mod_rewrite`/`AllowOverride FileInfo` aktiv sein.
  Die öffentliche URL `/out.php` ist absichtlich nur virtuell und wird durch
  die Rewrite-Regel an `index.php` übergeben.
- Nginx + PHP-FPM: Der Serveradmin benötigt folgende wesentliche Regeln:

  ```nginx
  root /var/www/lexnova/httpdocs;

  # Virtuelle öffentliche PHP-URL; es gibt keine Datei httpdocs/out.php.
  location = /out.php {
      include fastcgi_params;
      fastcgi_param SCRIPT_FILENAME $document_root/index.php;
      fastcgi_param SCRIPT_NAME /index.php;
      fastcgi_pass unix:/run/php/php8.4-fpm.sock;
  }

  location = /index.php {
      include fastcgi_params;
      fastcgi_param SCRIPT_FILENAME $document_root/index.php;
      fastcgi_pass unix:/run/php/php8.4-fpm.sock;
  }

  location / {
      try_files $uri $uri/ /index.php?$query_string;
  }

  location ~ \.php$ { return 404; }
  location ~ /\. { deny all; }
  ```

  Pfad und PHP-FPM-Socket sind hostabhängig.
- TLS wird vom Hoster eingerichtet. `app.base_url` muss genau der öffentlichen
  HTTPS-URL entsprechen, weil Session-Cookies und Passkeys an diese Origin
  gebunden sind.

## Voraussetzungen

**Pflicht:**
- PHP 8.4.1+
- Native PHP-Extensions: `fileinfo`, `filter`, `intl`, `json`, `openssl`, `pdo`
  und `redis` (PhpRedis 6+)
- Native Extensions empfohlen, geprüfter Composer-Fallback enthalten:
  `ctype`, `mbstring` und `sodium`
- PDO-Treiber: `pdo_sqlite`, `pdo_mysql` oder `pdo_pgsql`
- Relationale SQL-Datenbank (SQLite 3.35+, MySQL 8+, MariaDB 10.10+ oder PostgreSQL 13+)
- Native libsodium (`sodium` ist seit PHP 7.2 standardmäßig enthalten) wird für
  Leistung und sichere Speicherbereinigung ausdrücklich bevorzugt

**Zur Laufzeit:**
- Schreibzugriff auf `data/` (bei SQLite) und `var/` (Cache und Logging).
  Beide Verzeichnisse werden bei Bedarf automatisch angelegt und sind nicht
  Teil des Repositorys.

`config/config.toml` und `data/install.pw` werden vom Installer mit Modus
`0600` angelegt. Nach erfolgreicher Installation genügt Schreibzugriff für PHP
auf `data/` (bei SQLite) und `var/`; `config/` kann anschließend
wieder schreibgeschützt werden.

Der Installer prüft alle Voraussetzungen automatisch und blockiert den
Fortschritt bei fehlenden Pflichtfähigkeiten. Ein verwendeter Polyfill wird
orange ausgewiesen, blockiert die Installation aber nicht.

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
   bin/lexnova install:check
   bin/lexnova install:prepare
   ```

   `install:check` prüft die CLI-SAPI. Die Voraussetzungstabelle des
   Webinstallers prüft die Webserver-/FPM-SAPI separat, da Shared Hosts je SAPI
   eine andere `php.ini` und andere Extensions laden können.

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
   - Öffentliche URLs entstehen beim Anlegen eines Dokuments und können danach
     im Adminbereich über „Anzeigen“ geöffnet werden
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

`config/security.toml` enthält die ausgelieferten Standardwerte. Werte in der
lokalen `config/config.toml` überschreiben diese Defaults, damit beispielsweise
HIBP und abweichende Rate-Limits pro Installation konfigurierbar sind.

Wichtige Abschnitte in `config/config.example.toml`:

| Abschnitt | Inhalt |
|---|---|
| `[db]` | Datenbankverbindung mit Treiber, Host, Port, Name, User und Passwort |
| `[security]` | `totp_app_key` (32 Byte hex, beim Install generiert) |
| `[security.rate_limit]` | `max_attempts`, `block_seconds` für Login-Brute-Force-Schutz |
| `[security.fail2ban]` | Optionales projektlokales Fail2ban-Signallog und Settings-Cache |
| `[twig]` | `cache = true` aktiviert Template-Cache (empfohlen für Produktion) |
| `[cache]` | Anwendungs-Cache: standardmäßig Dateisystem; optional Valkey mit klassischen Verbindungsfeldern |

`[app].base_url` muss die öffentliche HTTPS-URL der Instanz enthalten. Sie ist
für sichere Session-Cookies und Passkeys erforderlich. Nur für lokale Entwicklung
ist `http://localhost` zulässig.

### Valkey

Valkey kann als verteilter Anwendungs-Cache genutzt werden, weil es zum Redis-Protokoll
kompatibel ist. In `config/config.toml`:

```
[cache]
adapter = "valkey"
host = "127.0.0.1"
port = 6379
database = 0
username = ""
password = ""
tls = false
namespace = "lexnova"
```

Ist Valkey nicht erreichbar, fällt LexNova kontrolliert auf den Dateisystem-Cache
zurück. Geänderte Dokumente invalidieren alle Sprachvarianten sofort.

Der Konfigurationsname `adapter = "valkey"` drückt bewusst das bevorzugte
Serverprodukt aus. Der Laminas-Redis-Adapter spricht über PhpRedis dasselbe
Protokoll mit Valkey und Redis. Unter `/admin/system` fragt LexNova ausschließlich für die gecachte
Systemdiagnose `INFO server` ab: `server_name=valkey` beziehungsweise
`valkey_version` wird grün als bevorzugtes Valkey angezeigt, ein tatsächlicher
Redis-Server gelb als kompatibel. Die Diagnose wird fünf Minuten lokal gecacht.
Ist `INFO server` durch die Server-ACL gesperrt, bleibt der Cache nutzbar, das
Produkt wird aber nicht geraten.

LexNova verwendet durchgängig Laminas Cache. `SimpleCacheDecorator` stellt den
Anwendungsdiensten weiterhin den frameworkneutralen PSR-16-Vertrag bereit. Der
Dateisystemadapter serialisiert Cachewerte selbst; beim Valkey-Adapter übernimmt
PhpRedis mit `Redis::SERIALIZER_PHP` die Serialisierung. Ein zusätzliches
Serializer-Paket ist daher nicht nötig.

`ext-redis` ist trotz seines Namens nur der übliche kompilierte PHP-Client für
das Redis-Protokoll. Er kann einen Valkey-Server ansprechen und verpflichtet
LexNova nicht zum Einsatz des Redis-Serverprodukts. `/admin/system` zeigt die
PhpRedis-Version getrennt vom tatsächlich erkannten Serverprodukt. Mezzio selbst
schreibt keinen Cache vor; die Wahl von Laminas Cache hält den Infrastrukturstack
innerhalb des Laminas-/Mezzio-Ökosystems.

Aktuell existieren folgende Cachewege:

| Zweck | Adapter |
|---|---|
| öffentliche Rechtsdokumente | Laminas Filesystem (Standard) oder Laminas Redis mit PhpRedis/Valkey |
| Twig-Templates | Dateisystem |
| Datenbank-Systemeinstellungen | gewählter Laminas-Adapter, eigener Namespace |
| Systemdiagnose | Laminas Filesystem über PSR-16, fünf Minuten |
| HIBP-Abfrageergebnisse | gewählter Laminas-Adapter, eigener Namespace |
| Installer-Rate-Limit vor vorhandener DB | geschützte lokale Dateien |

Dokumente, Systemeinstellungen und HIBP verwenden getrennte Cache-Namespaces.
Ein nicht erreichbarer Valkey-/Redis-Protokollserver fällt pro Cachebereich auf
geschützte Verzeichnisse unter `var/cache/` zurück. Datenbank und öffentliche
Dokumentausgabe bleiben auch bei Cachefehlern funktionsfähig.

Die admin-geschützte Seite `/admin/system` ist die allgemeine
Systeminformationsseite der Installation. Sie zeigt LexNova- und
Komponentenversionen, Host/OS, Kernel und Architektur, Webserver/SAPI, relevante
PHP-Limits und Erweiterungen, PDO- und Datenbankinformationen, Cache-Client und
-Server, Sicherheitsstatus, Speicherplatz sowie die Schreibbarkeit der
Runtime-Verzeichnisse. Passwörter, App-Schlüssel und sonstige Secrets werden
nicht ausgegeben.

Die Datenbankanbindung läuft über Doctrine DBAL und PDO. Unterstützt sind SQLite,
MySQL/MariaDB und PostgreSQL. Zugangsdaten und Cache-Secrets werden in den
Systeminformationen niemals ausgegeben.

## CLI

```
bin/lexnova entity:list                         Alle Entities auflisten
bin/lexnova install:check                       PHP, Extensions, PDO-Treiber und Rechte prüfen
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
- TOTP Zwei-Faktor-Authentifizierung (SHA-256, 6-stellig, 30-Sekunden-Fenster)
  - Mehrere TOTP-Keys pro Benutzer möglich (z. B. Smartphone + YubiKey)
  - QR-Code bei der Einrichtung als SVG inline gerendert
  - Empfohlene Apps: Aegis, 2FAS, Ente Auth, KeePassXC oder Raivo
- Rate Limiting: Login und TOTP-Versuche werden nach konfigurierbarer Anzahl
  für eine konfigurierbare Zeitspanne gesperrt (IP-basiert)
- Optionales Fail2ban-Signallog unter `var/log/fail2ban.log`; Aktivierung über
  `config.toml` oder mit Datenbankvorrang im Adminbereich
- Passkeys/WebAuthn: passwortloser Login über Plattform-Authenticator oder
  FIDO2-Sicherheitsschlüssel
  - mehrere Passkeys pro Benutzer mit Bezeichnung und letztem Nutzungszeitpunkt
  - frei vergebbare und nachträglich änderbare Bezeichnung
  - verständliche Einordnung als externer FIDO2-Hardware-Key, integrierter
    Geräte-Passkey, synchronisierter Geräte-/Cloud-Passkey oder Smartphone/
    anderes Gerät; zusätzlich Backupstatus und – sofern offengelegt – AAGUID
  - Registrierung, Umbenennung und einzelne Löschung im Adminbereich
  - Passkey-only-Benutzer ohne freigeschalteten Passwort-Login
  - Schutz vor dem Deaktivieren des Passworts ohne vorhandenen Passkey
  - Schutz vor dem Löschen des letzten Passkeys eines Passkey-only-Kontos

Ein Herstellername wird nicht aus Transport oder AAGUID geraten. Die
Registrierung fordert aus Datenschutzgründen keine direkte Attestation an;
Authenticator und Browser dürfen identifizierende Daten daher ausblenden. Eine
belastbare Hersteller-/Modellzuordnung benötigt später einen geprüften und
regelmäßig aktualisierten FIDO-Metadatendienst. Die Oberfläche kennzeichnet
diesen Wert bis dahin ausdrücklich als nicht zuverlässig ermittelbar.
WebAuthns `authenticatorAttachment` (`platform` oder `cross-platform`) wird bei
neuen Registrierungen zusätzlich im Credential-Datensatz gespeichert. Zusammen
mit den gemeldeten Transporten erlaubt dies die genannte Einordnung, aber keine
Behauptung, ob ein integrierter Passkey ausschließlich in Software oder in TPM/
Secure Enclave abgesichert ist.

### Entities (Rechtliche Einheiten)

- Anlegen, Bearbeiten, Löschen
- Kontaktdaten als Freitext (mehrzeilig, je Zeile ein Adressbestandteil)
- Die Betreiber-Entity wird automatisch beim Install angelegt

### Dokumente

- Anlegen, Bearbeiten, Löschen
- Typen: `imprint` (Impressum), `privacy` (Datenschutzerklärung)
- Mehrsprachig: pro Dokument ein BCP 47-Sprachcode (z. B. `de`, `en`, `fr-CH`)
- Freies Versionslabel (z. B. `2024-01`, `v3`); noch keine unveränderliche
  Revisionshistorie
- Jedes Dokument erhält einen eigenen zufälligen 32-Zeichen-Hex-Hash
- Direkt-Link „Anzeigen" öffnet die öffentliche URL im neuen Tab

### Benutzer

- Anlegen, Passwort setzen, Löschen; der aktuelle Prototyp erlaubt bislang nur
  die Systemrolle `admin`
- TOTP-Keys verwalten (einzelne Keys löschen oder alle zurücksetzen)

### Audit-Log

- Die letzten 50 Admin-Aktionen werden im Dashboard angezeigt
- Erfasst: Zeitpunkt, Akteur, Aktion, Ziel, Detail, IP-Adresse

## Öffentliche URLs

```
/out.php?typ=imprint&hash={document-hash}
/out.php?typ=privacy&hash={document-hash}
```

`out.php` ist keine zweite PHP-Datei. Apache leitet diese virtuelle URL über
`httpdocs/.htaccess` an `index.php`; bei Nginx übernimmt die oben gezeigte
`location`-Regel dieselbe Aufgabe. Andere Webserver müssen `/out.php` ebenfalls
unter Beibehaltung von Pfad und Query-String an `httpdocs/index.php` übergeben.

Hash und Typ werden gemeinsam gegen dieselbe Dokumentzeile geprüft. Der Hash ist
zusätzlich datenbankweit eindeutig; ein Impressum-Hash kann daher nicht als
Datenschutzerklärung ausgegeben werden. Sprachvarianten sind eigenständige
Dokumente mit jeweils eigener URL.

### Fehlerseiten

Auch alle anderen nicht vorhandenen Pfade werden vom Webserver intern an
`httpdocs/index.php` übergeben. LexNova rendert sie über den zentralen
`NotFoundHandler` und `templates/error/404.html.twig`; die ursprünglich
aufgerufene URL bleibt im Browser erhalten und die Antwort hat den echten
HTTP-Status 404. Es gibt deshalb keine sichtbare Umleitung auf eine technische
URL wie `index.php?mode=404`.

Die Fehlerseiten 404 und 500 verwenden das gemeinsame Twig-Grundtemplate
`templates/error/layout.html.twig`. Weitere Fehlerseiten können dieses Template
erweitern, ohne Gestaltung und Struktur erneut anzulegen.

### SEO und Caching

- Jede öffentliche Seite enthält:
  - `<link rel="canonical">` auf ihre Dokument-URL
  - `<link rel="alternate" hreflang="...">` für jede verfügbare Sprachversion
  - Sprachumschalter-Navigation (nur bei mehreren Sprachversionen sichtbar)
- HTTP-Header auf öffentlichen Dokumenten:
  - `Cache-Control: public, max-age=3600, stale-while-revalidate=86400`
  - 404-Antworten: `Cache-Control: no-store`
- Admin- und Installer-Seiten: `<meta name="robots" content="noindex, nofollow">`
- Zentrale HTTP-Sicherheitsheader: CSP, `nosniff`, Frame-Schutz,
  `Referrer-Policy`, `Permissions-Policy` und HSTS bei HTTPS

## Datenbankmigrationen

Frische Installationen verwenden automatisch das passende aktuelle Schema für
SQLite, MySQL/MariaDB oder PostgreSQL. Die folgenden Migrationen sind nur für
ältere Installationen nötig:

```
sql/migrations/001_multi_totp_keys.sql                # nur SQLite, altes Single-TOTP-Schema
sql/migrations/002_webauthn_credentials.sql          # SQLite
sql/migrations/002_webauthn_credentials.mysql.sql    # MySQL/MariaDB
sql/migrations/002_webauthn_credentials.pgsql.sql    # PostgreSQL
sql/migrations/003_document_public_hash.sql          # SQLite
sql/migrations/003_document_public_hash.mysql.sql    # MySQL 8 / MariaDB 10.10+
sql/migrations/003_document_public_hash.pgsql.sql    # PostgreSQL 13+
sql/migrations/004_system_settings.sql               # SQLite
sql/migrations/004_system_settings.mysql.sql         # MySQL/MariaDB
sql/migrations/004_system_settings.pgsql.sql         # PostgreSQL
sql/migrations/005_passwordless_auth.sql             # SQLite
sql/migrations/005_passwordless_auth.mysql.sql       # MySQL/MariaDB
sql/migrations/005_passwordless_auth.pgsql.sql       # PostgreSQL
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
| `laminas/laminas-cache` | Cache-Abstraktion mit PSR-16-Decorator |
| `laminas/laminas-cache-storage-adapter-filesystem` | Geschützter lokaler Cache für Dokumente, Settings, HIBP und Diagnose |
| `laminas/laminas-cache-storage-adapter-redis` | PhpRedis-basierter Valkey-/Redis-Protokollcache |
| `laminas/laminas-diactoros` | PSR-7 HTTP Message Implementierung |
| `laminas/laminas-filter` | Filter-Chain (StringTrim, Callback) für Input-Validierung |
| `laminas/laminas-i18n` | Sicheres Laden der PHP-Array-Übersetzungskataloge und I18n-Grundlage |
| `laminas/laminas-inputfilter` | Gemeinsame Input-/Filter-/Validator-Pipeline aller HTTP-Formulare |
| `laminas/laminas-validator` | Einzelne Validatoren (NotEmpty, StringLength, InArray, Callback) |
| `mezzio/mezzio` | PSR-15 Middleware-Framework |
| `mezzio/mezzio-csrf` | CSRF-Token-Schutz für alle Formulare |
| `mezzio/mezzio-fastroute` | FastRoute-Adapter für Mezzio |
| `mezzio/mezzio-session` | Session-Middleware |
| `mezzio/mezzio-session-ext` | PHP-native Session-Implementierung |
| `mezzio/mezzio-twigrenderer` | Twig-Template-Renderer für Mezzio |
| `monolog/monolog` | Logging (Datei-Handler) |
| `paragonie/sodium_compat` | Bekannter, Symfony-unabhängiger Pure-PHP-Fallback für die verwendeten Sodium-Secretbox-Funktionen |
| `paragonie/sodium_compat_ext_sodium` | Offizieller Composer-Provider, durch den Sodium Compat `ext-sodium` erfüllen kann |
| `php-di/php-di` | Dependency-Injection-Container |
| `phpdocumentor/reflection-docblock` | PHPDoc-Typinformationen für die WebAuthn-Deserialisierung |
| `psr/clock` | PSR-20 Clock-Interface (für testbare Zeitstempel) |
| `psr/simple-cache` | PSR-16 Simple Cache Interface |
| `spomky-labs/otphp` | TOTP/HOTP-Implementierung (RFC 6238) |
| `symfony/cache` | Beibehaltene PSR-6/PSR-16-Alternative; der aktive Anwendungscache nutzt Laminas Cache |
| `symfony/console` | CLI-Framework für `bin/lexnova`-Befehle |
| `symfony/polyfill-ctype` | Fallback für `ctype_*`; die native Extension bleibt schneller |
| `symfony/polyfill-iconv` | Automatischer `iconv`-Fallback für eingeschränkte Shared Hosts |
| `symfony/polyfill-mbstring` | Fallback für die verwendeten `mb_*`-Funktionen über Iconv |
| `symfony/property-info` | Typinformationen für den von WebAuthn erzeugten Symfony ObjectNormalizer |
| `symfony/serializer` | JSON-Serialisierung der WebAuthn-Optionen und Credentials |
| `twig/twig` | Template-Engine |
| `web-auth/webauthn-lib` | WebAuthn/FIDO2-Passkeys: Registrierung im Adminbereich und passwortloser Login |

Die Symfony-Komponenten für PropertyInfo und Serializer sind kein zweites
Anwendungsframework. `web-auth/webauthn-lib` erzeugt seinen Serializer über
`WebauthnSerializerFactory` und verwendet dabei ausdrücklich Symfony
`ObjectNormalizer`, `PropertyInfoExtractor`, `PhpDocExtractor` und
`ReflectionExtractor`. Laminas Filter/Validator normalisieren und prüfen
HTTP-Eingaben; sie ersetzen diese Objekt-Deserialisierung nicht.

`laminas-inputfilter` und `laminas-i18n` werden derzeit aus ihren kommenden
3.0-Zweigen auf feste Commitstände im Lockfile aufgelöst. Nur diese Zweige sind
mit dem für PHP 8.5 nötigen Laminas ServiceManager 4 sowie Filter/Validator 3
kompatibel; die letzten stabilen 2.x-Ausgaben verlangen ServiceManager 3. Vor
einem stabilen Release muss diese Übergangslösung erneut gegen veröffentlichte
3.0-Versionen geprüft werden. `symfony/cache` bleibt entsprechend der
Projektvorgabe installiert, ist aber nicht der aktive Cachepfad.

### Entwicklung (`require-dev`)

| Paket | Zweck |
|---|---|
| `friendsofphp/php-cs-fixer` | Code-Style-Prüfung und -Formatierung (PSR-12 + Symfony-Preset) |
| `laminas/laminas-cache-storage-adapter-memory` | Flüchtiger Laminas-Cache für isolierte Tests |
| `phpstan/phpstan` | Statische Analyse, Level 6 |

### QA-Skripte

```
composer analyse       PHPStan-Analyse (Level 6, --memory-limit=512M)
composer cs-check      PHP-CS-Fixer Dry-Run (nur prüfen)
composer cs-fix        PHP-CS-Fixer mit automatischer Korrektur
composer test          Integrations- und Security-Regressionstests
composer qa            analyse + cs-check + Tests
```

## Hinweise

- Dokumente werden als Freitext gespeichert (kein erzwungenes Format).
- Passwörter werden mit Argon2id gehasht (Parameter in `config/security.toml`).
- TOTP-Secrets werden mit XSalsa20-Poly1305 (libsodium) verschlüsselt gespeichert.
- Ohne native Sodium-Extension übernimmt `paragonie/sodium_compat` die
  verwendeten Secretbox-Funktionen. Pure PHP kann Schlüsselbuffer nicht so
  zuverlässig wie `sodium_memzero()` löschen; `/install` und `/admin/system`
  kennzeichnen diesen funktionalen, aber weniger leistungsfähigen Fallback deshalb
  sichtbar und empfehlen weiterhin die native Extension.
- Admin-Zugang ist vor der Installation vollständig gesperrt (`InstalledCheckMiddleware`).
- CSRF-Schutz ist auf allen Formularen aktiv.
- Zeilenenden in Kontaktdaten und Dokumentinhalten werden serverseitig auf LF normalisiert (Windows-`\r\n` → `\n`).
