# LexNova Core

## Voraussetzungen
- PHP 8.4+
- Relationale SQL-Datenbank (SQLite, MariaDB, PostgreSQL)

## Installation
1. Installer aufrufen: `/install`
2. Im Installer folgende Daten eingeben:
   - Install-Passwort (wird automatisch erzeugt und einmalig angezeigt)
   - Datenbankverbindung (SQLite-Pfad oder Host/Name/User/Passwort)
   - Admin-Benutzername + Passwort
   - Standard-Sprache (BCP 47, z. B. `de`, `en-US`)
3. Nach erfolgreicher Installation:
   - `data/install.lock` wird erstellt
   - `configs/config.toml` enthält die Konfiguration
   - `data/install.pw` kann entfernt werden

## Konfiguration
- Vorlage: `config.example.toml`
- Installiert: `configs/config.toml` (wird vom Installer erstellt)
- Sicherheitseinstellungen: `configs/security.toml` (im Repository enthalten)

## CLI
```
bin/lexnova user:create <username>      Neuen Admin-User anlegen
bin/lexnova user:list                   Alle User auflisten
bin/lexnova user:set-password <user>    Passwort zurücksetzen
```

## Nutzung
- Öffentliche Dokumente: `/{hash}/{imprint|privacy}[/{lang}]`
  - Beispiel: `/abc123.../imprint` oder `/abc123.../privacy/de`
  - `{lang}` ist ein optionaler BCP 47 Tag; ohne Angabe wird die neueste Version geliefert
- Admin-Bereich: `/admin`
  - Entities, Dokumente und Benutzer verwalten

## Hinweise
- Dokumente werden als Freitext (Markdown oder HTML) gespeichert.
- Passwörter werden mit Argon2id gehasht (Konfiguration in `configs/security.toml`).
- Admin-Zugang ist vor der Installation gesperrt.
- CSRF-Schutz ist auf allen Formularen aktiv.
