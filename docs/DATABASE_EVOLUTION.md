# Datenbank- und Migrationsstrategie

## Ausgangslage

Bislang wurde keine produktive LexNova-Instanz eingerichtet. Vor der ersten
veröffentlichten Alpha darf das Basisschema deshalb noch bereinigt und durch das
mandantenfähige Zielmodell ersetzt werden. Es besteht aktuell keine Verpflichtung,
ungetestete Entwicklungsdaten dauerhaft kompatibel zu halten.

Ab dem ersten veröffentlichten Installationsstand werden bestehende Migrationen
nicht mehr verändert. Jede Schemaänderung erhält dann eine neue, vorwärts
ausführbare Migration.

## Zielmodell

Die folgenden Tabellen bilden die geplanten stabilen Grenzen. Konkrete SQL-Dateien
werden erst nach Prüfung der Beziehungen für SQLite, MySQL/MariaDB und PostgreSQL
erstellt.

| Tabelle | Zweck |
|---|---|
| `users` | Globale Benutzeridentität und Systemrolle |
| `user_authenticators` | TOTP-, Passkey- und spätere Recovery-Metadaten |
| `user_sessions` | Widerrufbare Sitzungen und Sicherheitsstatus |
| `workspaces` | Mandant beziehungsweise persönlicher/Team-Bereich |
| `workspace_members` | Mitgliedschaft und Workspace-Rolle |
| `plans` | Benannte Planvorlage |
| `plan_limits` | Normalisierte Limits je Ressourcentyp |
| `workspace_plans` | Aktiver Plan eines Workspace |
| `legal_entities` | Betreiber innerhalb eines Workspace |
| `publication_targets` | Domain, App oder sonstiges Veröffentlichungsziel |
| `legal_documents` | Stabile Dokumentidentität und öffentlicher Hash |
| `document_revisions` | Unveränderliche Inhaltsstände |
| `document_permissions` | Optionale Freigabe über Workspace-Rollen hinaus |
| `audit_events` | Append-only Sicherheits- und Fachereignisse |
| `rate_limit_buckets` | Datenbankgestütztes Authentifizierungs-Limiting |
| `schema_migrations` | Bereits ausgeführte Migrationen |

## Zentrale Regeln

1. Jeder fachliche Datensatz trägt eine nicht-nullbare `workspace_id`.
2. Fremdschlüssel werden in der Datenbank erzwungen und sinnvoll indiziert.
3. Rollen, Dokumenttypen und Statuswerte erhalten Datenbank-Constraints.
4. Öffentliche Hashes entstehen mit `random_bytes()` und sind datenbankweit
   eindeutig.
5. `typ` und öffentlicher Hash werden gemeinsam gegen dasselbe Dokument geprüft.
6. Revisionen sind append-only. Update und Delete auf veröffentlichte Revisionen
   sind in der Anwendung verboten.
7. Löschungen fachlicher Inhalte erfolgen zunächst als Archivierung. Harte
   Löschung ist ein ausdrücklicher administrativer Vorgang mit Audit-Ereignis.
8. Planlimits werden in derselben Transaktion geprüft, in der ein Datensatz
   angelegt oder reaktiviert wird.
9. Zeitstempel werden in UTC gespeichert und erst in der Oberfläche lokalisiert.
10. Secrets und Credential-Rohdaten stehen nie im Audit-Log.

## Dokument und Revision

`legal_documents` hält die stabile Identität:

```text
id
workspace_id
entity_id
target_id
public_hash
type
language
status
published_revision_id
created_by
created_at
archived_at
```

`document_revisions` hält unveränderliche Stände:

```text
id
document_id
revision_number
previous_revision_id
content
content_hash
change_note
created_by
created_at
```

Der öffentliche Link bleibt stabil. Beim Veröffentlichen ändert sich nur
`published_revision_id`. Der `content_hash` beweist die Unverändertheit eines
gespeicherten Revisionsinhalts, ist aber kein öffentlicher Zugriffstoken.

## Migrationsmechanismus

Vor der ersten Alpha wird ein Migrationsrunner eingeführt:

- Migrationen besitzen eine unveränderliche Versionsnummer und Prüfsumme.
- `schema_migrations` speichert Version, Prüfsumme und Ausführungszeit.
- Vor einem Update wird eine Datenbanksicherung verlangt und geprüft.
- Bereits ausgeführte Migrationen werden niemals erneut oder verändert ausgeführt.
- Der Runner beendet sich bei unbekannter oder geänderter Prüfsumme.
- SQLite und PostgreSQL verwenden nach Möglichkeit DDL-Transaktionen.
- Für MySQL/MariaDB werden nicht transaktionale DDL-Schritte einzeln und
  wiederanlauffähig entworfen.
- Anwendungscode startet nicht gegen eine zu alte oder zu neue Schema-Version.

Geplante Bedienwege:

```text
bin/lexnova database:status
bin/lexnova database:migrate
bin/lexnova database:verify
```

Der Browser-Updater darf denselben Migrationsdienst verwenden, benötigt aber
eine erneute Admin-Authentifizierung, Wartungsmodus und CSRF-Schutz.

## Entwicklungsregel vor der ersten Alpha

Das gegenwärtige Schema wird nicht schrittweise um zufällige Spalten erweitert.
Zuerst werden Workspace-Besitz, Revisionen, Rollen und Limits gemeinsam entworfen.
Erst danach werden das neue Basisschema und die zugehörigen Services in einem
zusammenhängenden Entwicklungsschritt umgesetzt und auf allen drei
Datenbankfamilien geprüft.
