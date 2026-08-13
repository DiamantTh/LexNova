# LexNova – Produktumfang

## Zweck

LexNova verwaltet, versioniert und veröffentlicht rechtliche Texte wie Impressum
und Datenschutzerklärung. Eine öffentliche Ausgabe wird über einen zufälligen,
nicht erratbaren Dokumentbezeichner angesprochen:

```text
/out.php?typ=imprint&hash=<document-hash>
/out.php?typ=privacy&hash=<document-hash>
```

Der Hash gehört zum jeweiligen Dokument. `typ` und `hash` müssen gemeinsam zum
gleichen Datensatz passen. Der Hash ist ein öffentlicher Bezeichner und kein
Zugriffsrecht.

LexNova ist kein allgemeines CMS, kein juristischer Textgenerator und kein
Ersatz für eine rechtliche Prüfung. Der Schwerpunkt liegt auf Verwaltung,
Zusammenarbeit, nachvollziehbaren Revisionen und stabiler Veröffentlichung.

## Bereiche und natürliche URL-Struktur

| Bereich | URL | Aufgabe |
|---|---|---|
| Verwaltung | `/verwaltung` | Eigene und gemeinsam verwaltete Rechtstexte |
| Benutzerkonto | `/user` | Profil, Passwort, Passkeys, TOTP und Sitzungen |
| Instanzverwaltung | `/admin` | Benutzer, Pläne, Systemzustand und Sicherheit |
| Öffentliche Ausgabe | `/out.php` | Auslieferung einer veröffentlichten Revision |
| Installation | `/install` | Einmalige Einrichtung der Instanz |

Nach dem Login ist `/verwaltung` der normale Startpunkt. `/admin` ist nicht der
Ort, an dem normale Benutzer ihre Dokumente anlegen.

## Mandantenmodell

Teams beziehungsweise Workspaces sind von Beginn an die Besitzgrenze aller
fachlichen Daten. Auch ein persönlicher Bereich wird intern als Workspace mit
genau einem Mitglied angelegt. Dadurch müssen persönliche Daten später nicht in
ein Teammodell migriert werden.

Ein Workspace besitzt:

- Mitglieder und deren Workspace-Rollen
- rechtliche Einheiten (Betreiber)
- Publikationsziele, beispielsweise eine Domain oder ein Projekt
- Dokumente und deren unveränderliche Revisionen
- Plan und Kontingente
- einen getrennten Audit-Verlauf

Ein Benutzer kann mehreren Workspaces angehören. Freigaben an einzelne Benutzer
werden über Mitgliedschaften oder ausdrücklich gesetzte Dokumentberechtigungen
abgebildet. Ein bloßer numerischer Datensatzschlüssel verleiht niemals Zugriff.

## Rollen und Berechtigungen

Systemrollen und Workspace-Rollen sind getrennt:

### Systemrollen

- `user`: verwendet LexNova innerhalb seiner Workspaces
- `admin`: verwaltet die gesamte Instanz; MFA ist verpflichtend

### Workspace-Rollen

- `owner`: Workspace, Plan und Mitglieder vollständig verwalten
- `manager`: Mitglieder und alle fachlichen Inhalte verwalten
- `editor`: Entities, Ziele und Dokumententwürfe bearbeiten
- `viewer`: Inhalte und Revisionen lesen

Die technische Prüfung erfolgt über Berechtigungen, nicht über sichtbare Menüs:

```text
workspace.read
workspace.update
workspace.members.manage
entity.read
entity.create
entity.update
entity.delete
target.read
target.create
target.update
target.delete
document.read
document.create
document.update
document.publish
document.archive
document.permissions.manage
revision.read
system.users.manage
system.plans.manage
system.security.manage
```

## Fachobjekte

### Rechtliche Einheit

Eine rechtliche Einheit enthält Betreibername und strukturierbare Kontaktdaten.
Sie gehört genau einem Workspace und kann von mehreren Dokumenten verwendet
werden.

### Publikationsziel

Ein Publikationsziel beschreibt, wo oder wofür ein Text verwendet wird, etwa
eine Domain, Subdomain, App oder Kampagne. Domain und Anzeigename sind getrennte
Felder. Ein Ziel gehört genau einem Workspace.

### Dokument

Ein Dokument verbindet:

- Workspace
- rechtliche Einheit
- Publikationsziel
- Typ (`imprint` oder `privacy`)
- Sprache als BCP-47-Tag
- stabilen öffentlichen Hash
- aktuell veröffentlichten Revisionsstand

Weitere Dokumenttypen werden später über eine kontrollierte Typdefinition
ergänzt und nicht als beliebiger Freitext akzeptiert.

### Revision

Jede inhaltliche Änderung erzeugt eine neue unveränderliche Revision. Eine
Revision speichert mindestens Inhalt, Autor, Erstellungszeit, Kommentar,
Inhaltshash und Vorgängerrevision. Veröffentlichen setzt nur den Zeiger des
Dokuments auf eine vorhandene Revision. Bestehende Revisionen werden weder
überschrieben noch physisch gelöscht.

Damit sind Änderungen nachvollziehbar. Der Begriff „revisionssicher“ wird nur
für diese Historie verwendet; das bisherige freie Versionsfeld allein erfüllt
diese Anforderung nicht.

## Pläne und Limits

Kontingente gelten pro Workspace und werden serverseitig innerhalb der
Schreibtransaktion geprüft. Der vorgesehene Standardplan erlaubt zunächst:

- 10 aktive Dokumente je Dokumenttyp
- 10 rechtliche Einheiten
- 10 Publikationsziele
- 10 Workspace-Mitglieder

Revisionen zählen nicht als neue Dokumente. Archivierte Dokumente zählen nicht
gegen das aktive Dokumentlimit. Systemadministratoren ändern Pläne und Limits,
umgehen die fachlichen Workspace-Limits aber nicht stillschweigend.

„Max Users“ bezeichnet die Zahl der Mitglieder eines Workspace, nicht die
globale Zahl der Benutzerkonten der Instanz.

## Nicht Bestandteil der ersten stabilen Version

- juristische Beratung oder Garantie der Textinhalte
- automatische Rechtstextgenerierung durch KI
- Plugin-Marktplatz
- Zahlungsabwicklung
- native Mobile-App
- Containerpflicht oder externer Worker
- beliebige CMS-Funktionen wie Beiträge, Medien oder Themes von Drittanbietern

## Lieferstufen

### `0.1.0-alpha`

- abgesicherte Installation und Anmeldung
- Benutzer- und Workspace-Grundmodell
- Rollen und serverseitige Berechtigungen
- Entities, Ziele und Dokumente
- unveränderliche Revisionen und Veröffentlichung
- öffentliche Hash-URLs
- Planlimits
- lokale Integrations- und Securitytests

### `0.2.0-alpha`

- Einladungen und gemeinsames Bearbeiten
- detaillierte Dokumentfreigaben
- Sitzungsverwaltung und Recovery-Abläufe
- vollständiger Migrationsrunner und Updateprüfung

### `1.0.0`

- dokumentierter Upgradepfad
- getestete Installation auf Apache und Nginx
- SQLite, MySQL/MariaDB und PostgreSQL als Testmatrix
- Backup-/Restore- und Sicherheitsdokumentation
- keine offenen bekannten Release-Blocker
