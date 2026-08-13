# Optionale Fail2ban-Anbindung auf Webhosting

## Zweck und Grenzen

LexNova kann fehlgeschlagene oder bereits durch den internen Rate Limiter
abgewiesene Sicherheitsversuche in eine besonders einfach auswertbare Datei
schreiben. Die Anwendung installiert und startet Fail2ban nicht selbst.

Die Integration benötigt innerhalb des Webspace weder `/etc/fail2ban` noch ein
systemweites `/var/log`. Standardmäßig wird diese Datei verwendet:

```text
<LexNova-Projekt>/var/log/fail2ban.log
```

Der PHP-Prozess benötigt nur Schreibrecht auf das projektlokale `var/log/`.
Fail2ban beziehungsweise eine vergleichbare Sperrlösung muss vom Hoster oder
Serverbetreiber so eingerichtet werden, dass sie diese Datei lesen kann. Bei
unterschiedlichen Chroot-Sichten kann der auf dem Host sichtbare Pfad vom
PHP-sichtbaren Pfad abweichen.

Auf einem reinen Shared-Hosting-Tarif ohne verwaltete Fail2ban-/Firewall-Funktion
kann der Webspace-Benutzer selbst keine hostweite IP-Sperre einrichten. Der
interne LexNova-Rate-Limiter bleibt deshalb immer der primäre Schutz.

## Aktivierung

Die ausgelieferte Konfiguration ist standardmäßig deaktiviert:

```toml
[security.fail2ban]
enabled = false
path = "var/log/fail2ban.log"
settings_cache_ttl = 60
```

Relative Pfade werden ausgehend vom LexNova-Projektverzeichnis aufgelöst. Ein
absoluter Systempfad ist nicht erforderlich.

Nach der Installation kann ein Systemadministrator die Aktivierung im
Adminbereich umschalten:

- `config.toml verwenden`: kein Datenbankwert; `enabled` aus der Datei gilt
- `In Datenbank aktivieren`: Datenbankwert `true` hat Vorrang
- `In Datenbank deaktivieren`: Datenbankwert `false` hat Vorrang

Der effektive Wert wird standardmäßig 60 Sekunden im PSR-16-Dateicache gehalten.
Damit wird `system_settings` nicht bei jedem Fehlversuch erneut gelesen. Eine
Änderung im Adminbereich invalidiert den Cache sofort.

Der Adminbereich zeigt neben dem effektiven Wert, dessen Quelle und dem absoluten
PHP-sichtbaren Pfad auch an, ob der Pfad voraussichtlich schreibbar ist. Das
Signallog arbeitet bewusst nach dem Best-Effort-Prinzip: Ist Cache oder Datei
vorübergehend nicht verfügbar, darf dadurch keine Anmeldung ausfallen.

## Welche Anmeldeinformationen gespeichert werden

LexNova trennt drei unterschiedliche Zwecke:

| Speicher | Inhalt | Verhalten |
|---|---|---|
| `audit_log` in der Datenbank | erfolgreiche und fehlgeschlagene Passwort-, TOTP- und Passkey-Ereignisse; IP und bekannter Akteur | fachlicher, dauerhafter Nachweis |
| `login_attempts` in der Datenbank | Zähler, Endpunkt, IP und Sperrzeit | technischer Zustand; wird nach Erfolg gelöscht beziehungsweise nach Ablauf wiederverwendet |
| `var/log/fail2ban.log` | nur UTC-Zeit, fester Marker und validierte IP | optionale, maschinenlesbare Sperrsignale |

Das Installer-Limit arbeitet schon vor einer eingerichteten Datenbank mit
gehashten IP-Dateinamen unter `var/cache/install-rate-limit/`. Ist die
Fail2ban-Ausgabe bereits über `config.toml` aktiv, schreibt ein falsches
Installer-Passwort zusätzlich das minimale Sperrsignal. Eine automatische
Aufbewahrungs- und Löschfrist für das DB-Audit ist derzeit noch nicht umgesetzt
und bleibt ein Releasepunkt vor `1.0.0`.

## Logformat

Jeder verwertbare Versuch erzeugt exakt eine Zeile:

```text
2026-08-13T14:25:31Z LEXNOVA_FAIL2BAN 192.0.2.44
```

Die Datei enthält absichtlich nur:

- UTC-Zeitstempel
- festen Marker `LEXNOVA_FAIL2BAN`
- durch PHP validierte IPv4- oder IPv6-Adresse

Benutzername, Passwort, TOTP-Code, Passkey-Daten, Session-ID und URL-Parameter
werden nicht in das Signallog geschrieben. Dadurch ist das Format stabil und
gegen eingeschleuste zusätzliche Logzeilen geschützt.

Ein Signal wird momentan geschrieben bei:

- fehlgeschlagenem Passwort-Login
- fehlgeschlagener TOTP-Prüfung
- fehlgeschlagenem Passkey-Abschluss
- falschem Installer-Passwort
- einem weiteren Versuch, während einer dieser internen Sperren bereits aktiv ist

Erfolgreiche und fehlgeschlagene Anmeldungen werden zusätzlich fachlich im
Datenbank-Audit erfasst. Das Fail2ban-Signallog ist ausschließlich ein minimales
IP-Sperrsignal.

## Einfacher Filter

Der Serverbetreiber kann einen Filter mit nur einer relevanten Regel verwenden:

```ini
[Definition]
failregex = ^\S+ LEXNOVA_FAIL2BAN <HOST>$
ignoreregex =
```

Wo der Hoster diesen Filter hinterlegt, hängt von dessen Verwaltung ab. LexNova
legt bewusst keine `/etc`-Datei und keinen `deploy/`-Ordner an.

Vor dem Aktivieren sollte der Serverbetreiber den tatsächlichen Pfad aus der
LexNova-Adminanzeige übernehmen und den Filter gegen eine Beispielzeile testen.

## Beispiel für eine Jail

Dieses Beispiel ist eine Vorlage für den Hoster beziehungsweise die verwaltete
Serveroberfläche. `<HOST-PFAD-ZU-LEXNOVA>` ist durch den dort sichtbaren Pfad zu
ersetzen:

```ini
[lexnova]
enabled = true
filter = lexnova
logpath = <HOST-PFAD-ZU-LEXNOVA>/var/log/fail2ban.log
port = http,https

findtime = 10m
maxretry = 10
bantime = 30m
bantime.increment = true
bantime.maxtime = 24h
```

LexNova begrenzt die betroffene Aktion bereits vor der Fail2ban-Sperre. Der
höhere Fail2ban-Schwellwert verhindert, dass wenige Tippfehler auf einer
gemeinsam genutzten NAT-Adresse sofort eine hostweite Sperre auslösen.

## Allgemeine HTTP-Anfragefluten

Das Signallog wird nicht bei jedem normalen Seitenaufruf beschrieben. Ein solches
Request-Logging würde unnötige I/O verursachen und wäre selbst eine zusätzliche
Angriffsfläche.

Fail2ban kann allgemeine Anfragefluten aus dem vorhandenen Webserver-Access-Log
erkennen, sofern der Hoster dies unterstützt. Gleichmäßiges Begrenzen vieler
HTTP-Anfragen sollte jedoch vor PHP durch den Webserver, einen Reverse Proxy oder
eine Hoster-WAF erfolgen. LexNova meldet an Fail2ban nur die semantisch bekannten
Authentifizierungs- und Installer-Verstöße.

## Reverse Proxy

LexNova verwendet standardmäßig `REMOTE_ADDR`. Ein ungeprüftes
`X-Forwarded-For` wird nicht als Sperradresse übernommen. Hinter einem Reverse
Proxy muss der Serverbetreiber zuerst eine feste Liste vertrauenswürdiger Proxies
und die korrekte Real-IP-Verarbeitung auf Webserverebene einrichten. Andernfalls
könnte die Proxy-Adresse statt des Clients gesperrt werden.
