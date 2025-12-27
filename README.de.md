# LexNova Core

## Voraussetzungen
- PHP 8.1+
- Relationale SQL-Datenbank (z.B. SQLite, MariaDB, PostgreSQL)

## Installation
1. Installer aufrufen: `install/`
2. Im Installer folgende Daten eingeben:
   - Install-Passwort (wird einmalig gespeichert)
   - Datenbank-Zugangsdaten
   - Admin-Benutzer + Passwort
3. Nach erfolgreicher Installation:
   - `install/install.lock` wird erstellt
   - `config/config.php` enthaelt die Konfiguration
   - `install/install.pw` kann entfernt werden

## Konfiguration
- Vorlage: `config/config.example.php`
- Installiert: `config/config.php` (wird vom Installer erstellt)

## Nutzung
- Oeffentlicher Einstieg: `index.php?hash=...&mode=imprint|privacy`
- Admin-Einstieg: `admin.php`

## Hinweise
- Dokumente werden als strukturierter Text gespeichert (Markdown oder einfacher Text).
- Anzeige erfolgt schreibgeschuetzt und sicher escaped.
- Passwoerter werden mit Argon2id gehasht (Parameter zentral in `config/security.php`).
- Admin-Zugang ist vor der Installation gesperrt.
