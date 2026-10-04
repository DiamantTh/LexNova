<script lang="ts">
  import type { BootstrapData } from '../bootstrap';
  import AppShell from '../components/AppShell.svelte';
  import NoticeList from '../components/NoticeList.svelte';
  import { records, strings, text, number } from '../lib/data';
  import { passkeysSupported, registerPasskey, stepUp } from '../lib/webauthn';

  let { data }: { data: BootstrapData } = $props();
  const csrf = $derived(text(data.csrfToken));
  const userId = $derived(number(data.currentUserId));
  const passkeys = $derived(records(data.currentPasskeys));
  const totpKeys = $derived(records(data.currentTotpKeys));
  const requiresStepUp = $derived(passkeys.length > 0 || totpKeys.length > 0);
  let label = $state('Mein Sicherheitsschlüssel');
  let status = $state('');
  let pending = $state(false);
  const supported = passkeysSupported();

  function removalMessage(value: unknown, fallback = ''): string {
    const message = text(value);
    const translations: Record<string, string> = {
      'Only one FIDO2 credential will remain. Confirm with the other FIDO2 credential.': 'Es bleibt nur noch ein FIDO2-Credential. Bestätige mit dem anderen FIDO2-Credential.',
      'This removes the last TOTP credential. Confirm with FIDO2.': 'Dies entfernt das letzte TOTP-Credential. Bestätige die Entfernung mit FIDO2.',
      'Only one TOTP authenticator will remain. Confirm with the other TOTP authenticator or FIDO2.': 'Es bleibt nur noch ein TOTP-Credential. Bestätige mit einem anderen TOTP-Credential oder FIDO2.',
      'The only FIDO2 credential cannot be removed in self-service. Use the authorized admin or recovery path.': 'Der einzige FIDO2-Schlüssel kann nicht im Self-Service entfernt werden. Nutze den autorisierten Admin-/Recovery-Pfad.',
      'The last TOTP credential requires a FIDO2 credential for removal. Use the authorized admin or recovery path.': 'Das letzte TOTP-Credential kann nur mit FIDO2 entfernt werden. Nutze sonst den autorisierten Admin-/Recovery-Pfad.',
      'Removing this credential would leave no valid sign-in path. Use the authorized admin or recovery path.': 'Danach bliebe kein gültiger Anmeldeweg. Nutze den autorisierten Admin-/Recovery-Pfad.',
      'Removing this TOTP would leave no valid sign-in path. Use FIDO2 or the authorized admin/recovery path.': 'Danach bliebe kein gültiger Anmeldeweg. Verwende FIDO2 oder den autorisierten Admin-/Recovery-Pfad.',
    };

    return (translations[message] ?? message) || fallback;
  }

  async function register(mode: 'hardware' | 'any'): Promise<void> {
    pending = true;
    status = 'FIDO2 wird vorbereitet …';
    try {
      if (requiresStepUp) await stepUp('auth.webauthn.add', `user:${userId}`, csrf);
      window.location.assign(await registerPasskey(userId, label, csrf, mode));
    } catch (error) {
      status = error instanceof Error ? error.message : 'Registrierung fehlgeschlagen.';
      pending = false;
    }
  }

  async function protectForm(event: SubmitEvent, action: string, target: string, allowTotpFallback = true): Promise<void> {
    const form = event.currentTarget;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.dataset.stepUpReady === '1') {
      delete form.dataset.stepUpReady;
      return;
    }
    event.preventDefault();
    try {
      await stepUp(action, target, csrf, allowTotpFallback);
      form.dataset.stepUpReady = '1';
      form.requestSubmit();
    } catch (error) {
      status = error instanceof Error ? error.message : 'Bestätigung fehlgeschlagen.';
    }
  }

  async function confirmAndProtect(event: SubmitEvent, label: string, action: string, target: string, allowTotpFallback: boolean, warning: string): Promise<void> {
    const detail = warning ? `\n\n${warning}` : '';
    if (!confirm(`${label} wirklich entfernen?${detail}`)) { event.preventDefault(); return; }
    await protectForm(event, action, target, allowTotpFallback);
  }
</script>

<AppShell title="Anmeldesicherheit" area="account" csrfToken={csrf}>
  <NoticeList errors={strings(data.errors)} messages={strings(data.messages)} />
  {#if data.authSetupRequired === true}
    <aside class="alert alert-warning mb-5"><div><strong>Erste Anmeldung</strong><p class="mt-1">Richte jetzt einen FIDO2-Schlüssel oder TOTP ein. Bis dahin bleibt der Zugang auf diese Sicherheitsseite beschränkt.</p></div></aside>
  {/if}
  <section class="card preset-filled-surface-100-900 p-6">
    <h2 class="h2">FIDO2-Sicherheitsschlüssel hinzufügen <span class="status-pill status-good">EMPFOHLEN</span></h2>
    <p class="mt-2 opacity-75">Für einen externen Schlüssel wird cross-platform angefordert. Verbindung und Transport sagen nichts Verlässliches über Hersteller oder Modell aus.</p>
    <div class="mt-5 flex flex-wrap items-end gap-3">
      <label class="label grow"><span>Name</span><input class="input" bind:value={label} maxlength="100"></label>
      <button class="btn preset-filled-primary-500" type="button" onclick={() => register('hardware')} disabled={!supported || pending}>{pending ? 'Warte auf FIDO2 …' : 'Sicherheitsschlüssel hinzufügen'}</button>
    </div>
    <div class="mt-3"><button class="btn preset-tonal-primary" type="button" onclick={() => register('any')} disabled={!supported || pending}>Passkey / anderes Gerät hinzufügen</button></div>
    <p class="mt-3 text-sm opacity-70" role="status">{status || (supported ? 'FIDO2 mit erfolgreicher User Verification.' : 'Dieser Browser unterstützt kein WebAuthn.')}</p>
  </section>

  <section class="card preset-filled-surface-100-900 mt-5 p-6">
    <h2 class="h2">TOTP <span class="status-pill status-warn">FALLBACK</span></h2>
    <p class="mt-2 opacity-75">{number(data.currentTotpCredentialCount)} aktive TOTP-Credentials. LexNova verwendet SHA-256 und achtstellige Codes.</p>
    <a class="btn preset-tonal-primary mt-4" href="/admin/totp/enroll">Authenticator hinzufügen</a>
  </section>

  <section class="card preset-filled-surface-100-900 mt-5 p-6">
    <h2 class="h2">Registrierte FIDO2-Schlüssel</h2>
    {#if passkeys.length === 0}<p class="mt-3 opacity-70">Noch kein FIDO2-Schlüssel registriert.</p>
    {:else}<div class="credential-list mt-4">{#each passkeys as passkey}
      <article class="credential-row"><div class="min-w-0 grow"><strong>{text(passkey.label, 'Passkey')}</strong><span class="ml-2 text-sm opacity-70">{text(passkey.kind, 'Authenticator')}</span>
        <details class="mt-1 text-sm"><summary class="cursor-pointer opacity-70">Details</summary><dl class="detail-list compact"><div><dt>Attachment</dt><dd>{text(passkey.attachment, 'nicht gemeldet')}</dd></div><div><dt>Transports</dt><dd>{Array.isArray(passkey.transports) && passkey.transports.length ? passkey.transports.join(', ') : 'nicht gemeldet'}</dd></div><div><dt>Backup Eligible / State</dt><dd>{passkey.backup_eligible === true ? `ja / ${passkey.backup_status === true ? 'ja' : 'nein'}` : passkey.backup_eligible === false ? 'nein' : 'nicht gemeldet'}</dd></div><div><dt>Erstellt</dt><dd>{text(passkey.created_at)}</dd></div><div><dt>Zuletzt benutzt</dt><dd>{text(passkey.last_used_at, 'noch nicht verwendet')}</dd></div>{#if passkey.aaguid}<div><dt>AAGUID</dt><dd><code>{text(passkey.aaguid)}</code></dd></div>{/if}<div><dt>Hersteller</dt><dd>nicht kryptographisch verifiziert</dd></div></dl></details>
      </div><div class="flex flex-col items-end gap-2">{#if passkey.removal_warning}<p class="max-w-xs text-right text-sm text-warning-700">{removalMessage(passkey.removal_warning)}</p>{/if}{#if passkey.removal_allowed === true}<form method="post" action={`/admin/users/${userId}/passkeys/${String(passkey.id)}/delete`} onsubmit={(event) => void confirmAndProtect(event, text(passkey.label, 'Passkey'), 'auth.webauthn.delete', `user:${userId}/passkey:${String(passkey.id)}`, false, removalMessage(passkey.removal_warning))}><input type="hidden" name="__csrf" value={csrf}><button class="btn preset-tonal-error" type="submit">Entfernen</button></form>{:else}<button class="btn preset-tonal-error" type="button" disabled>Entfernen</button><p class="max-w-xs text-right text-sm opacity-70">{removalMessage(passkey.removal_reason, 'Der letzte FIDO2-Schlüssel kann nur über den autorisierten Admin-/Recovery-Pfad entfernt werden.')}</p>{/if}</div></article>
    {/each}</div>{/if}
  </section>

  <section class="card preset-filled-surface-100-900 mt-5 p-6">
    <h2 class="h2">TOTP-Authenticatoren</h2>
    {#if totpKeys.length === 0}<p class="mt-3 opacity-70">Noch kein TOTP-Authenticator registriert.</p>
    {:else}<div class="credential-list mt-4">{#each totpKeys as key}
      <div class="credential-row"><div class="grow"><strong>{text(key.label, 'Authenticator')}</strong><span class="ml-2 text-sm opacity-70">Erstellt {text(key.created_at)} · zuletzt benutzt {text(key.last_used_at, 'nie')}</span></div><div class="flex flex-col items-end gap-2">{#if key.removal_warning}<p class="max-w-xs text-right text-sm text-warning-700">{removalMessage(key.removal_warning)}</p>{/if}{#if key.removal_allowed === true}<form method="post" action={`/admin/users/${userId}/totp-keys/${String(key.id)}/delete`} onsubmit={(event) => void confirmAndProtect(event, `TOTP ${text(key.label)}`, 'auth.totp.delete', `user:${userId}/totp:${String(key.id)}`, Array.isArray(key.removal_methods) && key.removal_methods.includes('totp'), removalMessage(key.removal_warning))}><input type="hidden" name="__csrf" value={csrf}><button class="btn preset-tonal-error" type="submit">Entfernen</button></form>{:else}<button class="btn preset-tonal-error" type="button" disabled>Entfernen</button><p class="max-w-xs text-right text-sm opacity-70">{removalMessage(key.removal_reason, 'Ohne FIDO2-Bestätigung ist diese Entfernung nicht möglich. Verwende den autorisierten Admin-/Recovery-Pfad.')}</p>{/if}</div></div>
    {/each}</div>{/if}
  </section>

  <section class="card preset-filled-surface-100-900 mt-5 p-6"><h2 class="h2">Passwort und Recovery</h2><p class="mt-2 opacity-75">Ein Passwort ist optional. Ein Konto ohne Passwortanmeldung funktioniert mit FIDO2. Wenn alle Faktoren verloren gehen, ist eine ausdrücklich auditierte Recovery erforderlich.</p></section>
</AppShell>
