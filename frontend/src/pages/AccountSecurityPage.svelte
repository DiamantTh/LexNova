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

  async function protectForm(event: SubmitEvent, action: string, target: string): Promise<void> {
    const form = event.currentTarget;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.dataset.stepUpReady === '1') {
      delete form.dataset.stepUpReady;
      return;
    }
    event.preventDefault();
    try {
      await stepUp(action, target, csrf);
      form.dataset.stepUpReady = '1';
      form.requestSubmit();
    } catch (error) {
      status = error instanceof Error ? error.message : 'Bestätigung fehlgeschlagen.';
    }
  }

  async function confirmAndProtect(event: SubmitEvent, label: string, action: string, target: string): Promise<void> {
    if (!confirm(`${label} wirklich löschen?`)) { event.preventDefault(); return; }
    await protectForm(event, action, target);
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
    <p class="mt-2 opacity-75">{totpKeys.length} registrierte Authenticator-App(s). LexNova verwendet SHA-256 und achtstellige Codes.</p>
    <a class="btn preset-tonal-primary mt-4" href="/admin/totp/enroll">Authenticator hinzufügen</a>
  </section>

  <section class="card preset-filled-surface-100-900 mt-5 p-6">
    <h2 class="h2">Registrierte FIDO2-Schlüssel</h2>
    {#if passkeys.length === 0}<p class="mt-3 opacity-70">Noch kein FIDO2-Schlüssel registriert.</p>
    {:else}<div class="credential-list mt-4">{#each passkeys as passkey}
      <article class="credential-row"><div class="min-w-0 grow"><strong>{text(passkey.label, 'Passkey')}</strong><span class="ml-2 text-sm opacity-70">{text(passkey.kind, 'Authenticator')}</span>
        <details class="mt-1 text-sm"><summary class="cursor-pointer opacity-70">Details</summary><dl class="detail-list compact"><div><dt>Attachment</dt><dd>{text(passkey.attachment, 'nicht gemeldet')}</dd></div><div><dt>Transports</dt><dd>{Array.isArray(passkey.transports) && passkey.transports.length ? passkey.transports.join(', ') : 'nicht gemeldet'}</dd></div><div><dt>Backup Eligible / State</dt><dd>{passkey.backup_eligible === true ? `ja / ${passkey.backup_status === true ? 'ja' : 'nein'}` : passkey.backup_eligible === false ? 'nein' : 'nicht gemeldet'}</dd></div><div><dt>Erstellt</dt><dd>{text(passkey.created_at)}</dd></div><div><dt>Zuletzt benutzt</dt><dd>{text(passkey.last_used_at, 'noch nicht verwendet')}</dd></div>{#if passkey.aaguid}<div><dt>AAGUID</dt><dd><code>{text(passkey.aaguid)}</code></dd></div>{/if}<div><dt>Hersteller</dt><dd>nicht kryptographisch verifiziert</dd></div></dl></details>
      </div><div class="flex gap-2"><form method="post" action={`/admin/users/${userId}/passkeys/${String(passkey.id)}/delete`} onsubmit={(event) => void confirmAndProtect(event, text(passkey.label, 'Passkey'), 'auth.webauthn.delete', `user:${userId}/passkey:${String(passkey.id)}`)}><input type="hidden" name="__csrf" value={csrf}><button class="btn preset-tonal-error" type="submit">Löschen</button></form></div></article>
    {/each}</div>{/if}
  </section>

  <section class="card preset-filled-surface-100-900 mt-5 p-6">
    <h2 class="h2">TOTP-Authenticatoren</h2>
    {#if totpKeys.length === 0}<p class="mt-3 opacity-70">Noch kein TOTP-Authenticator registriert.</p>
    {:else}<div class="mt-4 grid gap-2">{#each totpKeys as key}
      <div class="credential-row"><div class="grow"><strong>{text(key.label, 'Authenticator')}</strong><span class="ml-2 text-sm opacity-70">Erstellt {text(key.created_at)} · zuletzt benutzt {text(key.last_used_at, 'nie')}</span></div><form method="post" action={`/admin/users/${userId}/totp-keys/${String(key.id)}/delete`} onsubmit={(event) => void confirmAndProtect(event, `TOTP ${text(key.label)}`, 'auth.totp.delete', `user:${userId}/totp:${String(key.id)}`)}><input type="hidden" name="__csrf" value={csrf}><button class="btn preset-tonal-error" type="submit">Widerrufen</button></form></div>
    {/each}</div>{/if}
  </section>

  <section class="card preset-filled-surface-100-900 mt-5 p-6"><h2 class="h2">Passwort und Recovery</h2><p class="mt-2 opacity-75">Ein Passwort ist optional. Ein Konto ohne Passwortanmeldung funktioniert mit FIDO2. Wenn alle Faktoren verloren gehen, ist eine ausdrücklich auditierte Recovery erforderlich.</p></section>
</AppShell>
