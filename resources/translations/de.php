<?php

/**
 * Deutsche Übersetzungen für LexNova.
 * Format: 'Englischer Schlüsseltext' => 'Deutsche Übersetzung'
 * Fehlende Einträge fallen auf den Schlüssel (= Englisch) zurück.
 */
return [

    // ── Öffentliche Dokumentseite ──────────────────────────────────────────
    'LexNova Legal Documents'            => 'LexNova Rechtsdokumente',
    'Imprint'                            => 'Impressum',
    'Privacy Policy'                     => 'Datenschutzerklärung',
    'Error'                              => 'Fehler',
    'Contact'                            => 'Kontakt',
    'Document'                           => 'Dokument',
    'Version %s · Updated %s'           => 'Version %s · Aktualisiert am %s',
    'Entity not found.'                  => 'Entität nicht gefunden.',
    'No document found for this entity.' => 'Kein Dokument für diese Entität gefunden.',

    // ── Admin Login ────────────────────────────────────────────────────────
    'Admin Login'  => 'Admin-Anmeldung',
    'Username'     => 'Benutzername',
    'Password'     => 'Passwort',
    'Sign in'      => 'Anmelden',

    // ── Admin Dashboard – allgemein ────────────────────────────────────────
    'Logout'             => 'Abmelden',
    'Created'            => 'Erstellt am',
    'Actions'            => 'Aktionen',

    // ── Benutzer ───────────────────────────────────────────────────────────
    'Create User'            => 'Benutzer anlegen',
    'Role'                   => 'Rolle',
    'Confirm password'       => 'Passwort bestätigen',
    'Users'                  => 'Benutzer',
    'No users yet.'          => 'Noch keine Benutzer.',
    'Update'                 => 'Aktualisieren',
    'New password (optional)' => 'Neues Passwort (optional)',
    'pw_hint'                => 'Min. %1$d Zeichen, max. %2$d, nur druckbare ASCII-Zeichen.',

    // ── Rechtliche Einheiten ───────────────────────────────────────────────
    'Create Legal Entity'  => 'Rechtliche Einheit anlegen',
    'Edit Entity'          => 'Einheit bearbeiten',
    'Update Entity'        => 'Einheit aktualisieren',
    'Contact Data'         => 'Kontaktdaten',
    'Create Entity'        => 'Einheit anlegen',
    'Legal Entities'       => 'Rechtliche Einheiten',
    'No entities yet.'     => 'Noch keine Einheiten.',
    'Hash'                 => 'Hash',

    // ── Dokumente ──────────────────────────────────────────────────────────
    'Create Document'      => 'Dokument anlegen',
    'Update Document'      => 'Dokument aktualisieren',
    'Edit Document'        => 'Dokument bearbeiten',
    'Entity'               => 'Einheit',
    'Type'                 => 'Typ',
    'Language'             => 'Sprache',
    'Content'              => 'Inhalt',
    'Cancel'               => 'Abbrechen',
    'Documents'            => 'Dokumente',
    'No documents yet.'    => 'Noch keine Dokumente.',
    'View'                 => 'Anzeigen',
    'Updated'              => 'Aktualisiert',
    '— select —'           => '— auswählen —',
    // ── Löschen ────────────────────────────────────────────────────────────────
    'Delete'                                           => 'Löschen',
    'Delete user permanently?'                         => 'Benutzer dauerhaft löschen?',
    'Delete entity and all its documents permanently?' => 'Einheit und alle zugehörigen Dokumente dauerhaft löschen?',
    'Delete document permanently?'                     => 'Dokument dauerhaft löschen?',
    // ── TOTP / Zwei-Faktor-Authentifizierung ───────────────────────────────
    'Two-Factor Authentication'                    => 'Zwei-Faktor-Authentifizierung',
    'Two-Factor Authentication (TOTP)'             => 'Zwei-Faktor-Authentifizierung (TOTP)',
    'TOTP Status'                                  => 'TOTP-Status',
    'active'                                       => 'aktiv',
    'inactive'                                     => 'inaktiv',
    'Enroll TOTP'                                  => 'TOTP einrichten',
    'Enroll'                                       => 'Einrichten',
    'Disable TOTP'                                 => 'TOTP deaktivieren',
    'Disable TOTP for this user?'                  => 'TOTP für diesen Benutzer deaktivieren?',
    'Authentication Code'                          => 'Authenticator-Code',
    'Authenticator Code'                           => 'Authenticator-Code',
    'Enter the 8-digit code from your authenticator app to complete sign-in.' => 'Gib den 8-stelligen Code aus deiner Authenticator-App ein, um die Anmeldung abzuschließen.',
    'Enter the 8-digit code from your authenticator app.' => 'Gib den 8-stelligen Code aus deiner Authenticator-App ein.',
    'Verify'                                       => 'Verifizieren',
    'Scan QR Code'                                 => 'QR-Code scannen',
    'Manual entry (Base32 secret):'                => 'Manuelle Eingabe (Base32-Secret):',
    'Copy provisioning URI'                        => 'Bereitstellungs-URI kopieren',
    'Confirm enrollment with a code:'              => 'Einrichtung mit einem Code bestätigen:',
    'Back to login'                                => 'Zurück zum Login',
    'App compatibility notice'                     => 'Hinweis zur App-Kompatibilität',
    'This application uses TOTP with SHA-256 and 8-digit codes. Google Authenticator is <strong>not supported</strong>. Recommended apps:' =>
        'Diese Anwendung verwendet TOTP mit SHA-256 und 8-stelligen Codes. Google Authenticator wird <strong>nicht unterstützt</strong>. Empfohlene Apps:',
    'Key label (e.g. Phone, YubiKey)'              => 'Key-Bezeichnung (z. B. Handy, YubiKey)',
    'A name to identify this key among multiple keys.' => 'Ein Name, um diesen Key unter mehreren zu unterscheiden.',
    'Add another TOTP key'                         => 'Weiteren TOTP-Key hinzufügen',
    'Delete TOTP key'                              => 'TOTP-Key löschen',

    // ── Audit-Log ──────────────────────────────────────────────────────────
    'Audit log'   => 'Audit-Protokoll',
    'When'        => 'Zeitpunkt',
    'Actor'       => 'Akteur',
    'Action'      => 'Aktion',
    'Target'      => 'Ziel',
    'Detail'      => 'Detail',

];

