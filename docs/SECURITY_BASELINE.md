# Sicherheitsbaseline

Diese Anforderungen gelten als Release-Kriterien und nicht als optionale
Erweiterungen.

## Authentifizierung

- Der primäre Login ist Username-first: Der Identifier bestimmt den Account,
  danach werden ausschließlich dessen registrierte WebAuthn-Credentials angeboten.
- WebAuthn verlangt User Verification, bevorzugt resident credentials,
  erzwingt keine Attestation und verwendet eine kurzlebige, einmalige Challenge
  mit strikter Origin- und RP-ID-Prüfung.
- Passkeys sind der bevorzugte Login. Dasselbe Credential kann für Login,
  Passwort-MFA und aktionsgebundenes Step-up verwendet werden.
- Ein Passkey-only-Konto ist ein regulärer Kontotyp. Das Passwort ist optional;
  nach Enrollment eines starken Faktors kann Passwortlogin diesen Faktor nicht
  umgehen.
- Verlorene Faktoren benötigen ein explizites, befristetes und einmaliges
  Aktivierungs-/Recovery-Ticket. Das Ticket wird gehasht gespeichert; Recovery
  widerruft bestehende Sitzungen und wird auditiert.
- Step-up-Grants sind kurzlebig, einmalig und an Benutzer, konkrete Aktion und
  konkretes Ziel gebunden. Step-up ändert keine Autorisierungsrechte.
- WebAuthn- und TOTP-Limits sind instanzweit konfigurierbar. Eine Absenkung
  löscht keine Credentials; Neuregistrierungen bleiben bis unterhalb des Limits
  gesperrt.
- Hardware-Enrollment fragt cross-platform Authenticator-Preference an.
  Transportdaten und Attachment gelten als gemeldete Metadaten, nicht als
  verifizierter Hersteller- oder Hardware-Nachweis.
- Passwörter werden mit Argon2id gespeichert und bei veralteten Parametern nach
  erfolgreicher Anmeldung neu gehasht.
- Fehlermeldung und wesentlicher Rechenweg unterscheiden nicht zwischen einem
  unbekannten Benutzer und einem falschen Passwort.
- Für Systemadministratoren ist Passkey oder Passwort plus WebAuthn/TOTP-MFA
  verpflichtend.
- Sicherheitskritische Änderungen verlangen eine frische Re-Authentifizierung.
- Passwortänderung, Rollenverlust oder Benutzerlöschung widerrufen vorhandene
  Sitzungen.
- Sitzungen besitzen Idle- und absolute Maximalzeiten.
- Authentifizierte Sitzungen sind widerrufbare Datensätze und werden bei
  sicherheitsrelevanten Accountänderungen beendet.
- Self-Service-Credentialänderungen sind ans eigene Konto gebunden. Recovery für
  fremde Konten erfordert eine explizite Operation, den Step-up des handelnden
  Administrators und ein Audit-Ereignis.
- Das Installer-Secret schützt den Bootstrap und ist nicht das spätere
  Benutzerpasswort. Der erste Administrator erhält standardmäßig ein einmaliges
  Passkey-Enrollment-Ticket.
- Bei vorhandenen Credentials werden RP-ID und Origin gepinnt. Ältere
  Datenbanken ohne Pin verwenden beim ersten Start mit Credentials die aktuell
  validierte Konfiguration und auditieren diesen Übergang; die frühere Domain
  lässt sich aus den alten Credential-Daten nicht zuverlässig ableiten.

## Autorisierung und Mandantentrennung

- Jede fachliche Abfrage enthält die Workspace-Grenze.
- Berechtigungen werden im Handler beziehungsweise Fachservice geprüft und nicht
  nur durch Navigation oder versteckte Buttons.
- Objektkennungen aus Request-Parametern werden niemals als Berechtigungsnachweis
  behandelt.
- Die Berechtigungsmatrix erhält automatisierte Positiv- und Negativtests.

## Eingaben und Ausgaben

- SQL wird ausschließlich parametrisiert ausgeführt.
- Freitext wird bei HTML-Ausgabe kontextgerecht escaped.
- Als HTML-sicher an Svelte übergebene Entity-Ausgaben dürfen keine ungeprüften Originalzeichen
  oder Attribute ausgeben.
- Requests und alle persistenten Textfelder besitzen serverseitige Größenlimits.
- Uploads werden erst eingeführt, wenn Typprüfung, Größenlimit, zufällige Namen
  und nicht öffentlicher Speicherort implementiert sind.

## Automatisierte Angriffe

- Login, TOTP, Passkey-Abschluss und Installer werden begrenzt.
- Limits berücksichtigen IP, Konto und globale Auffälligkeit, ohne eine gemeinsame
  NAT-IP dauerhaft auszusperren.
- CAPTCHA ergänzt das Limiting nur adaptiv und ersetzt es nicht.
- Vorgesehene FOSS-Lösung ist lokal gehostetes ALTCHA Open Source mit der
  offiziellen PHP-Bibliothek.
- LexNova ergänzt Ablaufzeit, Sitzungs-/Aktionsbindung, Einmalverwendung und
  Replay-Schutz selbst. Es erfolgen keine externen CAPTCHA-Aufrufe.

## HTTP und Betrieb

- Nur `httpdocs/` ist öffentlich.
- Produktivbetrieb setzt HTTPS voraus.
- CSP, `nosniff`, Frame-Schutz, Referrer- und Permissions-Policy werden zentral
  gesetzt.
- Canonical- und andere absolute URLs entstehen aus `app.base_url`, nicht aus
  einem ungeprüften Host-Header.
- Konfiguration, Logs, Cache, SQLite-Datenbank und Secrets liegen außerhalb des
  DocumentRoot.
- Produktionsfehler zeigen keine Datenbank-, Pfad- oder Stacktrace-Details.

## Revisionen und Audit

- Dokumentrevisionen und Audit-Ereignisse werden append-only geschrieben.
- Das Audit enthält Akteur, Workspace, Aktion, Ziel, Zeit und Request-Kontext,
  aber keine Passwörter, TOTP-Secrets oder vollständigen Credentials.
- Aufbewahrung, Export und Integritätsprüfung werden vor `1.0.0` dokumentiert.

## Pflichtprüfungen vor einem Tag

```text
composer validate --strict
composer audit --locked
composer qa
composer check-platform-reqs --no-dev
```

Zusätzlich werden Installation, Migration und Kernabläufe auf den unterstützten
PHP- und Datenbankversionen geprüft. Das Ergebnis wird mit Datum und Commit unter
`docs/verification/` abgelegt.
