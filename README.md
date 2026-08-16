# LexNova Core

**English** | [Deutsch](README.de.md)

LexNova manages, versions, and publishes imprint and privacy texts for personal
areas and teams. The authoritative planned scope is documented in
[docs/PRODUCT_SCOPE.md](docs/PRODUCT_SCOPE.md), the planned database evolution
in [docs/DATABASE_EVOLUTION.md](docs/DATABASE_EVOLUTION.md), and the security
requirements in [docs/SECURITY_BASELINE.md](docs/SECURITY_BASELINE.md).
The optional Fail2ban signal output designed for shared hosting is described in
[docs/FAIL2BAN.md](docs/FAIL2BAN.md).

The current administration prototype does not yet fully implement roles,
workspaces, plan limits, or truly immutable document revisions. Until these
points have been implemented and tested with migrations, the source must be
treated as pre-release development rather than a stable production version.

## Shared-hosting operation

LexNova is suitable for traditional PHP hosting without containers and without
a release system. The **only** public directory is `httpdocs/`; the project root
must never be configured as the DocumentRoot. This keeps `config/`, `data/`,
`src/`, `templates/`, `vendor/`, and `var/` outside web access.

- Apache 2.4: On shared hosting without a custom vHost,
  `httpdocs/.htaccess` applies. With custom server configuration, `httpdocs/`
  must be the DocumentRoot and `mod_rewrite`/`AllowOverride FileInfo` must be
  enabled. The public `/out.php` URL is intentionally virtual and is passed to
  `index.php` by the rewrite rule.
- Nginx + PHP-FPM: The server administrator needs the following essential
  rules:

  ```nginx
  root /var/www/lexnova/httpdocs;

  # Virtual public PHP URL; there is no httpdocs/out.php file.
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

  The path and PHP-FPM socket depend on the host.
- TLS is configured by the hosting provider. `app.base_url` must exactly match
  the public HTTPS URL because session cookies and passkeys are bound to this
  origin.

## Requirements

**Required:**

- PHP 8.4.1+
- Native PHP extensions: `fileinfo`, `filter`, `intl`, `json`, `openssl`, and
  `pdo`
- Native extensions recommended, with a verified Composer fallback included:
  `ctype`, `mbstring`, and `sodium`
- PDO driver: `pdo_sqlite`, `pdo_mysql`, or `pdo_pgsql`
- Relational SQL database (SQLite 3.35+, MySQL 8+, MariaDB 10.10+, or PostgreSQL 13+)
- Native libsodium (`sodium` has been included with PHP by default since PHP
  7.2) is explicitly preferred for performance and secure memory clearing

**Optional:** PhpRedis 6+ and
`laminas/laminas-cache-storage-adapter-redis:^3.2` enable the Valkey cache
backend. They are deliberately not installation requirements because the
default filesystem cache needs neither one. Install the adapter only when
Valkey is selected:

```bash
composer require laminas/laminas-cache-storage-adapter-redis:^3.2
```

**At runtime:**

- Write access to `data/` (for SQLite) and `var/` (cache and logging).
  Both directories are created automatically when needed and are not part of
  the repository.

`config/config.toml` and `data/install.pw` are created by the installer with
mode `0600`. After installation, PHP only needs write access to `data/` (for
SQLite) and `var/`; `config/` can then be made read-only again.

The installer checks all requirements automatically and blocks progress when a
required capability is missing. An active polyfill is shown in orange but does
not block installation.

## Installation

1. Place the project directory above the web root and set the DocumentRoot to
   `httpdocs/`.
2. Install dependencies (or upload a `vendor/` directory generated with the
   same PHP version):

   ```
   composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
   ```

3. Prepare the installer password. With shell access:

   ```
   bin/lexnova install:check
   bin/lexnova install:prepare
   ```

   `install:check` checks the CLI SAPI. The web installer's requirements table
   separately checks the web server/FPM SAPI because shared hosts may load a
   different `php.ini` and different extensions for each SAPI.

   Without shell access, set `LEXNOVA_INSTALL_PASSWORD` as a secret environment
   variable in the hosting control panel. The installer imports it on the first
   request. The password is intentionally never generated or displayed through
   the web interface.
4. Open the installer at `/install`.
5. The first step displays a **System requirements** check:
   - Green ✓ — requirement satisfied
   - Red ✗ — required prerequisite missing (installation blocked)
   - Orange ⚠ — recommendation missing (installation remains possible)
6. Complete the form:
   - Installer password (stored once in `data/install.pw`)
   - Database connection (SQLite path or host/name/user/password)
   - Administrator username and password
   - Default language (BCP 47, for example `de` or `en-US`)
   - **Operator entity**: name and contact details of the operating organization
7. After successful installation:
   - `data/install.lock` is created and locks the installer
   - `config/config.toml` contains the configuration, including `totp_app_key`
   - Public URLs are generated when a document is created and can then be
     opened through “View” in the administration area
   - `data/install.pw` may be removed after installation

> **Note for fresh clones:** If `vendor/` is missing, the application responds
> with HTTP 503 and explains that `composer install` must be run first.

## Updating without releases

A single shared-host installation can update LexNova directly in its working
directory; a separate release directory is not required. Back up the database
and `config/config.toml` before every update. Then:

1. Apply the relevant SQL migrations from `sql/migrations/` to the production
   database in numeric order (before updating the code).
2. Update the code, for example with `git pull --ff-only`.
3. Install the exact dependencies from the lockfile:

   ```
   composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
   ```

4. Reload PHP-FPM or restart the Apache PHP process if the host runs OPcache
   without timestamp validation.

Never run `composer update` on the production system. A release directory is
useful for updates with several concurrent web processes or when even a brief
inconsistency during `git pull` is unacceptable, but it is not required for a
single shared host.

## Configuration

- Template: `config/config.example.toml`
- Installed: `config/config.toml` (created by the installer)
- Security defaults: `config/security.toml` (included in the repository)

`config/security.toml` contains the shipped defaults. Values in the local
`config/config.toml` override these defaults, allowing settings such as HIBP and
custom rate limits to be configured per installation.

Important sections in `config/config.example.toml`:

| Section | Purpose |
|---|---|
| `[db]` | Database connection using driver, host, port, name, user, and password |
| `[security]` | `totp_app_key` (32-byte hex value generated during installation) |
| `[security.rate_limit]` | `max_attempts` and `block_seconds` for login brute-force protection |
| `[security.fail2ban]` | Optional project-local Fail2ban signal log and settings cache |
| `[twig]` | `cache = true` enables the template cache (recommended in production) |
| `[cache]` | Application cache: filesystem by default; optional Valkey with traditional connection fields |

`[app].base_url` must contain the public HTTPS URL of the instance. It is
required for secure session cookies and passkeys. `http://localhost` is allowed
only for local development.

### Valkey

Valkey can be used as a distributed application cache because it is compatible
with the Redis protocol. In `config/config.toml`:

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

If Valkey is unavailable, LexNova falls back safely to the filesystem cache.
Changed documents immediately invalidate all language variants.

PhpRedis and the Laminas Redis adapter are optional as a pair. A base
installation without either component is valid; `/install` reports PhpRedis as
recommended and `/admin/system` reports the extension and adapter separately.

The configuration name `adapter = "valkey"` deliberately expresses the
preferred server product. The Laminas Redis adapter uses PhpRedis to communicate
with both Valkey and Redis through the same protocol. For the cached system
diagnostics only, `/admin/system` requests `INFO server`: `server_name=valkey`
or `valkey_version` is displayed in green as the preferred Valkey server, while
an actual Redis server is shown in yellow as compatible. The diagnostic result
is cached locally for five minutes. If the server ACL blocks `INFO server`, the
cache remains usable, but LexNova does not guess the product.

LexNova uses Laminas Cache throughout. `SimpleCacheDecorator` continues to
expose the framework-neutral PSR-16 contract to application services. The
filesystem adapter serializes cache values itself; for the Valkey adapter,
PhpRedis performs serialization with `Redis::SERIALIZER_PHP`. No additional
serializer package is required.

Despite its name, `ext-redis` is merely the conventional compiled PHP client
for the Redis protocol. It can connect to a Valkey server and does not require
LexNova to use the Redis server product. `/admin/system` shows the PhpRedis
version separately from the detected server product. Mezzio itself does not
mandate a cache; choosing Laminas Cache keeps the infrastructure stack within
the Laminas/Mezzio ecosystem.

The following cache paths currently exist:

| Purpose | Adapter |
|---|---|
| Public legal documents | Laminas Filesystem (default) or Laminas Redis with PhpRedis/Valkey |
| Twig templates | Filesystem |
| Database-backed system settings | Selected Laminas adapter, separate namespace |
| System diagnostics | Laminas Filesystem through PSR-16, five minutes |
| HIBP query results | Selected Laminas adapter, separate namespace |
| Installer rate limit before a database exists | Protected local files |

Documents, system settings, and HIBP use separate cache namespaces. If a
Valkey/Redis-protocol server cannot be reached, each cache area falls back to a
protected directory below `var/cache/`. The database and public document output
remain functional during cache failures.

The administrator-protected `/admin/system` page is the installation's general
system information page. It shows LexNova and component versions, host/OS,
kernel and architecture, web server/SAPI, relevant PHP limits and extensions,
PDO and database information, cache client and server, security status, disk
space, and runtime-directory writability. Passwords, application keys, and
other secrets are never displayed.

Database access uses Doctrine DBAL and PDO. SQLite, MySQL/MariaDB, and
PostgreSQL are supported. Database credentials and cache secrets are never
included in system information.

## CLI

```
bin/lexnova entity:list                         List all entities
bin/lexnova install:check                       Check PHP, extensions, PDO drivers, and permissions
bin/lexnova install:prepare                     Generate a one-time installer password
bin/lexnova user:create <username>              Create a new administrator user
bin/lexnova user:delete <username> [-y]         Delete an administrator user
bin/lexnova user:list                           List all users (including TOTP status)
bin/lexnova user:set-password <username>        Reset a password
bin/lexnova user:totp-reset <username> [-y]     Delete all TOTP keys of a user
```

## Administration area (`/admin`)

### Authentication

- Login with username and password
  - Password strength is assessed with zxcvbn (score 0–4) when a password is set
- TOTP two-factor authentication (SHA-256, 6 digits, 30-second window)
  - Multiple TOTP keys per user (for example, smartphone and YubiKey)
  - Setup QR code rendered inline as SVG
  - Recommended apps: Aegis, 2FAS, Ente Auth, KeePassXC, or Raivo
- Rate limiting: Login and TOTP attempts are blocked by IP for a configurable
  period after a configurable number of failures
- Optional Fail2ban signal log at `var/log/fail2ban.log`; enable it through
  `config.toml` or, with database priority, in the administration area
- Passkeys/WebAuthn: passwordless login through a platform authenticator or
  FIDO2 security key
  - Multiple passkeys per user, with a label and last-used timestamp
  - User-defined labels that can be changed later
  - Clear classification as an external FIDO2 hardware key, integrated device
    passkey, synchronized device/cloud passkey, or smartphone/other device;
    also the backup status and, when disclosed, the AAGUID
  - Registration, renaming, and individual deletion in the administration area
  - Passkey-only users without enabled password login
  - Protection against disabling the password without an existing passkey
  - Protection against deleting the final passkey of a passkey-only account

A vendor name is not guessed from the transport or AAGUID. For privacy reasons,
registration does not request direct attestation; authenticators and browsers
may therefore withhold identifying data. A reliable vendor/model association
will later require a verified and regularly updated FIDO Metadata Service.
Until then, the interface explicitly marks this value as not reliably
detectable. WebAuthn's `authenticatorAttachment` (`platform` or
`cross-platform`) is additionally stored with new credentials. Together with
the reported transports, this allows the classification above, but it cannot
prove whether an integrated passkey is secured exclusively in software or by a
TPM/Secure Enclave.

### Entities (legal entities)

- Create, edit, and delete
- Contact information as free text (multiple lines, one address component per line)
- The operator entity is created automatically during installation

### Documents

- Create, edit, and delete
- Types: `imprint`, `privacy`
- Multilingual: one BCP 47 language tag per document (for example `de`, `en`, `fr-CH`)
- Free-form version label (for example `2024-01` or `v3`); immutable revision
  history is not yet implemented
- Every document receives its own random 32-character hexadecimal hash
- The “View” direct link opens the public URL in a new tab

### Users

- Create, set passwords, and delete; the current prototype currently supports
  only the system role `admin`
- Manage TOTP keys (delete individual keys or reset all keys)

### Audit log

- The 50 most recent administrator actions are displayed in the dashboard
- Recorded fields: timestamp, actor, action, target, detail, and IP address

## Public URLs

```
/out.php?typ=imprint&hash={document-hash}
/out.php?typ=privacy&hash={document-hash}
```

`out.php` is not a second PHP file. Apache routes this virtual URL to
`index.php` through `httpdocs/.htaccess`; with Nginx, the `location` rule shown
above performs the same task. Other web servers must likewise pass `/out.php`
to `httpdocs/index.php` while preserving the path and query string.

The hash and type are checked together against the same document row. The hash
is also unique throughout the database, so an imprint hash cannot be used to
retrieve a privacy document. Language variants are separate documents with
their own URLs.

### Error pages

All other nonexistent paths are also passed internally by the web server to
`httpdocs/index.php`. LexNova renders them through the central
`NotFoundHandler` and `templates/error/404.html.twig`; the originally requested
URL remains visible in the browser and the response carries the genuine HTTP
404 status. There is therefore no visible redirect to a technical URL such as
`index.php?mode=404`.

The 404 and 500 error pages use the shared Twig base template
`templates/error/layout.html.twig`. Further error pages can extend this
template without duplicating design and structure.

### SEO and caching

- Every public page contains:
  - `<link rel="canonical">` to its document URL
  - `<link rel="alternate" hreflang="...">` for every available language variant
  - Language-switcher navigation (visible only when multiple variants exist)
- HTTP headers on public documents:
  - `Cache-Control: public, max-age=3600, stale-while-revalidate=86400`
  - 404 responses: `Cache-Control: no-store`
- Administration and installer pages: `<meta name="robots" content="noindex, nofollow">`
- Central HTTP security headers: CSP, `nosniff`, frame protection,
  `Referrer-Policy`, `Permissions-Policy`, and HSTS over HTTPS

## Database migrations

Fresh installations automatically use the appropriate current schema for
SQLite, MySQL/MariaDB, or PostgreSQL. The following migrations are needed only
for older installations:

```
sql/migrations/001_multi_totp_keys.sql                # SQLite only, old single-TOTP schema
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

Requires SQLite ≥ 3.35.0 (for `DROP COLUMN`).

## Dependencies (Packagist)

### Runtime (`require`)

| Package | Purpose |
|---|---|
| `bjeavons/zxcvbn-php` | Password-strength assessment (score 0–4) when setting passwords |
| `devium/toml` | TOML parser for `config.toml` and `security.toml` |
| `doctrine/dbal` | Database abstraction (SQLite, MariaDB, PostgreSQL) |
| `endroid/qr-code` | QR-code generation (SVG) during TOTP setup |
| `laminas/laminas-cache` | Cache abstraction with PSR-16 decorator |
| `laminas/laminas-cache-storage-adapter-filesystem` | Protected local cache for documents, settings, HIBP, and diagnostics |
| `laminas/laminas-cache-storage-adapter-redis` | Optional PhpRedis-based Valkey/Redis-protocol cache (declared under Composer `suggest`) |
| `laminas/laminas-diactoros` | PSR-7 HTTP message implementation |
| `laminas/laminas-filter` | Filter chain (StringTrim, Callback) for input validation |
| `laminas/laminas-i18n` | Secure loading of PHP-array translation catalogs and I18n foundation |
| `laminas/laminas-inputfilter` | Shared input/filter/validator pipeline for all HTTP forms |
| `laminas/laminas-validator` | Individual validators (NotEmpty, StringLength, InArray, Callback) |
| `mezzio/mezzio` | PSR-15 middleware framework |
| `mezzio/mezzio-csrf` | CSRF-token protection for all forms |
| `mezzio/mezzio-fastroute` | FastRoute adapter for Mezzio |
| `mezzio/mezzio-session` | Session middleware |
| `mezzio/mezzio-session-ext` | PHP-native session implementation |
| `mezzio/mezzio-twigrenderer` | Twig template renderer for Mezzio |
| `monolog/monolog` | Logging (file handler) |
| `paragonie/sodium_compat` | Established Symfony-independent pure-PHP fallback for the Sodium secretbox functions used by LexNova |
| `paragonie/sodium_compat_ext_sodium` | Official Composer provider allowing Sodium Compat to satisfy `ext-sodium` |
| `php-di/php-di` | Dependency-injection container |
| `phpdocumentor/reflection-docblock` | PHPDoc type information for WebAuthn deserialization |
| `psr/clock` | PSR-20 clock interface (for testable timestamps) |
| `psr/simple-cache` | PSR-16 Simple Cache interface |
| `spomky-labs/otphp` | TOTP/HOTP implementation (RFC 6238) |
| `symfony/cache` | Retained PSR-6/PSR-16 alternative; the active application cache uses Laminas Cache |
| `symfony/console` | CLI framework for `bin/lexnova` commands |
| `symfony/polyfill-ctype` | Fallback for `ctype_*`; the native extension remains faster |
| `symfony/polyfill-iconv` | Automatic `iconv` fallback for restricted shared hosts |
| `symfony/polyfill-mbstring` | Fallback for the used `mb_*` functions through Iconv |
| `symfony/property-info` | Type information for the Symfony ObjectNormalizer used by WebAuthn |
| `symfony/serializer` | JSON serialization of WebAuthn options and credentials |
| `twig/twig` | Template engine |
| `web-auth/webauthn-lib` | WebAuthn/FIDO2 passkeys: registration in the administration area and passwordless login |

The Symfony PropertyInfo and Serializer components are not a second application
framework. `web-auth/webauthn-lib` creates its serializer through
`WebauthnSerializerFactory` and explicitly uses Symfony `ObjectNormalizer`,
`PropertyInfoExtractor`, `PhpDocExtractor`, and `ReflectionExtractor`. Laminas
Filter/Validator normalizes and validates HTTP input; it does not replace this
object deserialization.

`laminas-inputfilter` and `laminas-i18n` are currently resolved from their
upcoming 3.0 branches at fixed commits in the lockfile. Only these branches are
compatible with Laminas ServiceManager 4 and Filter/Validator 3, which are
required for PHP 8.5; the latest stable 2.x releases require ServiceManager 3.
Before a stable release, this temporary solution must be checked again against
published 3.0 versions. `symfony/cache` remains installed as required by the
project, but it is not the active cache path.

### Development (`require-dev`)

| Package | Purpose |
|---|---|
| `friendsofphp/php-cs-fixer` | Code-style checks and formatting (PSR-12 + Symfony preset) |
| `laminas/laminas-cache-storage-adapter-memory` | Volatile Laminas cache for isolated tests |
| `phpstan/phpstan` | Static analysis, level 6 |

### QA scripts

```
composer analyse       PHPStan analysis (level 6, --memory-limit=512M)
composer cs-check      PHP-CS-Fixer dry run (check only)
composer cs-fix        PHP-CS-Fixer with automatic corrections
composer test          Integration and security regression tests
composer qa            Analysis + code-style check + tests
```

## Notes

- Documents are stored as free text (no mandatory format).
- Passwords are hashed with Argon2id (parameters in `config/security.toml`).
- TOTP secrets are stored encrypted with XSalsa20-Poly1305 (libsodium).
- Without the native Sodium extension, `paragonie/sodium_compat` provides the
  secretbox functions used by LexNova. Pure PHP cannot clear key buffers as
  reliably as `sodium_memzero()`; `/install` and `/admin/system` therefore mark
  this functional but slower fallback visibly and continue to recommend the native
  extension.
- Administrator access is fully blocked before installation (`InstalledCheckMiddleware`).
- CSRF protection is active on every form.
- Line endings in contact data and document content are normalized to LF on the
  server (Windows `\r\n` → `\n`).
