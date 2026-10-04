<script lang="ts">
  import type { BootstrapData } from '../bootstrap';
  import AppShell from '../components/AppShell.svelte';
  import NoticeList from '../components/NoticeList.svelte';
  import { flag, number, record, records, strings, text } from '../lib/data';
  import { stepUp } from '../lib/webauthn';

  let { data }: { data: BootstrapData } = $props();
  const csrf = $derived(text(data.csrfToken));
  const users = $derived(records(data.users));
  const passkeyMap = $derived(record(data.passkeys));
  const totpMap = $derived(record(data.totpKeys));
  const min = $derived(number(data.passwordMin, 16));
  const max = $derived(number(data.passwordMax, 256));
  const actorId = $derived(number(data.currentUserId));
  let authentication = $state('passkey');
  let status = $state('');
  const credentials = (id: unknown) => records(passkeyMap[String(id)]);
  const totp = (id: unknown) => records(totpMap[String(id)]);

  async function protectForm(event: SubmitEvent, action: string, target: string, confirmText?: string): Promise<void> {
    const form = event.currentTarget;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.dataset.stepUpReady === '1') { delete form.dataset.stepUpReady; return; }
    if (confirmText && !confirm(confirmText)) { event.preventDefault(); return; }
    event.preventDefault();
    try {
      await stepUp(action, target, csrf);
      form.dataset.stepUpReady = '1';
      form.requestSubmit();
    } catch (error) {
      status = error instanceof Error ? error.message : 'Bestätigung fehlgeschlagen.';
    }
  }

  function updateAction(event: SubmitEvent, user: Record<string, unknown>): void {
    const form = event.currentTarget;
    if (!(form instanceof HTMLFormElement)) return;
    const values = new FormData(form);
    const id = number(user.id);
    const newPassword = String(values.get('new_password') ?? '');
    const enabled = String(values.getAll('password_login_enabled').at(-1) ?? '0') === '1';
    const wasEnabled = flag(user.password_login_enabled);
    const action = newPassword !== '' ? 'auth.password.change'
      : enabled !== wasEnabled ? (enabled ? 'auth.password.enable' : 'auth.password.disable')
      : 'auth.policy.change';
    void protectForm(event, action, `user:${id}/account`);
  }
</script>

<AppShell title="Benutzerkonten" area="admin" csrfToken={csrf}>
  <NoticeList errors={strings(data.errors)} messages={strings(data.messages)} />
  <nav class="subnav"><a href="/admin">Übersicht</a><a class="active" href="/admin/users">Benutzer</a><a href="/admin/security">Sicherheit</a></nav>

  <section class="card preset-filled-surface-100-900 mt-6 p-6">
    <h2 class="h2">Konto anlegen</h2>
    <form method="post" action="/admin/users/create" class="mt-5 grid gap-4" onsubmit={(event) => void protectForm(event, 'auth.policy.change', 'instance:users/create')}>
      <input type="hidden" name="__csrf" value={csrf}>
      <div class="form-grid">
        <label class="label"><span>Benutzername</span><input class="input" name="username" required maxlength="100" autocomplete="off"></label>
        <label class="label"><span>Rolle</span><select class="select" name="role"><option value="admin">Administrator</option></select></label>
        <label class="label"><span>Erste Anmeldung</span><select class="select" name="authentication" bind:value={authentication}><option value="passkey">Passkey-Aktivierungsticket</option><option value="password">Passwort als Übergang</option></select></label>
      </div>
      {#if authentication === 'password'}
        <div class="form-grid"><label class="label"><span>Passwort</span><input class="input" type="password" name="password" required minlength={min} maxlength={max} autocomplete="new-password"></label><label class="label"><span>Bestätigung</span><input class="input" type="password" name="password_confirm" required minlength={min} maxlength={max} autocomplete="new-password"></label></div>
      {/if}
      <button class="btn preset-filled-primary-500" type="submit">Konto anlegen</button>
    </form>
    <p class="mt-3 text-sm opacity-70">Passkey-only-Konten starten gesperrt. Führe danach <code>bin/lexnova user:activation-create BENUTZERNAME</code> aus und übermittle den Einmalcode sicher.</p>
  </section>

  <p class="mt-5 text-sm" role="status">{status}</p>
  <div class="mt-4 grid gap-5">
    {#each users as user}
      <section class="card preset-filled-surface-100-900 p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div><h2 class="h2">{text(user.username)}</h2><p class="mt-1 opacity-70">ID {String(user.id)} · {text(user.role)} · angelegt {text(user.created_at)}</p></div>
          {#if flag(user.activation_required)}<span class="status-pill status-warn">Aktivierung ausstehend</span>{/if}
        </div>

        <form method="post" action={`/admin/users/${String(user.id)}/update`} class="form-grid mt-5" onsubmit={(event) => updateAction(event, user)}>
          <input type="hidden" name="__csrf" value={csrf}><input type="hidden" name="role" value="admin">
          <label class="flex items-center gap-3"><input type="hidden" name="password_login_enabled" value="0"><input class="checkbox" type="checkbox" name="password_login_enabled" value="1" checked={flag(user.password_login_enabled)}> Passwort-Anmeldung erlauben</label>
          <label class="label"><span>Neues Passwort (optional)</span><input class="input" type="password" name="new_password" minlength={min} maxlength={max} autocomplete="new-password"></label>
          <button class="btn preset-tonal" type="submit">Konto aktualisieren</button>
        </form>

        {#if number(user.id) !== actorId}<form method="post" action={`/admin/users/${String(user.id)}/activation-ticket`} class="mt-4" onsubmit={(event) => void protectForm(event, 'auth.recovery', `user:${String(user.id)}/activation/actor:${actorId}`, flag(user.activation_required) ? `Neues einmaliges Enrollment-Ticket für ${text(user.username)} ausstellen?` : `Recovery-Ticket für ${text(user.username)} ausstellen? Dadurch werden dessen aktive Sitzungen widerrufen.`)}><input type="hidden" name="__csrf" value={csrf}><button class="btn preset-tonal" type="submit">{flag(user.activation_required) ? 'Enrollment-Ticket neu ausstellen' : 'Recovery-Ticket ausstellen'}</button></form>{/if}

        <h3 class="h3 mt-6">FIDO2-Credentials ({credentials(user.id).length})</h3>
        {#if credentials(user.id).length === 0}<p class="mt-2 text-sm opacity-70">Keine registrierten Schlüssel.</p>
        {:else}<div class="credential-list mt-3">{#each credentials(user.id) as passkey}
          <article class="credential-row"><div class="grow"><strong>{text(passkey.label, 'Passkey')}</strong><span class="ml-2 text-sm opacity-70">{text(passkey.kind)}</span><details class="mt-1 text-sm"><summary class="cursor-pointer opacity-70">Details</summary><p class="mt-1 opacity-75">{Array.isArray(passkey.transports) && passkey.transports.length ? passkey.transports.join(', ') : 'Transports nicht gemeldet'} · {text(passkey.attachment, 'Attachment nicht gemeldet')} · AAGUID {text(passkey.aaguid, 'nicht gemeldet')}</p><p class="text-xs opacity-70">Hersteller nicht kryptographisch verifiziert · erstellt {text(passkey.created_at)} · zuletzt benutzt {text(passkey.last_used_at, 'nie')}</p></details>
            {#if number(user.id) === actorId}<form method="post" action={`/admin/users/${String(user.id)}/passkeys/${String(passkey.id)}/update`} class="mt-2 flex max-w-lg gap-2" onsubmit={(event) => void protectForm(event, 'auth.webauthn.rename', `user:${String(user.id)}/passkey:${String(passkey.id)}`)}><input type="hidden" name="__csrf" value={csrf}><input class="input" name="label" value={text(passkey.label)} required maxlength="100"><button class="btn preset-tonal" type="submit">Name speichern</button></form>{/if}
          </div><form method="post" action={`/admin/users/${String(user.id)}/passkeys/${String(passkey.id)}/delete`} onsubmit={(event) => void protectForm(event, 'auth.recovery', `user:${String(user.id)}/passkey:${String(passkey.id)}/actor:${actorId}`, `FIDO2-Credential von ${text(user.username)} wirklich zurücksetzen?`)}><input type="hidden" name="__csrf" value={csrf}><button class="btn preset-tonal-error" type="submit">Recovery: widerrufen</button></form></article>
        {/each}</div>{/if}

        <details class="mt-5"><summary class="cursor-pointer font-semibold">TOTP-Authenticatoren ({totp(user.id).length})</summary>
          {#if totp(user.id).length > 0}<div class="mt-3 grid gap-2">{#each totp(user.id) as key}
            <form method="post" action={`/admin/users/${String(user.id)}/totp-keys/${String(key.id)}/delete`} class="credential-row" onsubmit={(event) => void protectForm(event, 'auth.recovery', `user:${String(user.id)}/totp:${String(key.id)}/actor:${actorId}`, `TOTP-Credential von ${text(user.username)} wirklich zurücksetzen?`)}><input type="hidden" name="__csrf" value={csrf}><span>{text(key.label)} · {flag(key.is_active) ? 'aktiv' : 'inaktiv'} · erstellt {text(key.created_at)} · zuletzt benutzt {text(key.last_used_at, 'nie')}</span><button class="btn preset-tonal-error" type="submit">Recovery: widerrufen</button></form>
          {/each}</div><form method="post" action={`/admin/totp/reset/${String(user.id)}`} class="mt-3" onsubmit={(event) => void protectForm(event, 'auth.recovery', `user:${String(user.id)}/totp:all/actor:${actorId}`, `Alle TOTP-Credentials von ${text(user.username)} zurücksetzen?`)}><input type="hidden" name="__csrf" value={csrf}><button class="btn preset-tonal-error" type="submit">Alle TOTP-Credentials zurücksetzen</button></form>{:else}<p class="mt-2 text-sm opacity-70">Keine TOTP-Credentials.</p>{/if}
        </details>

        {#if number(user.id) !== actorId}
          <form method="post" action={`/admin/users/${String(user.id)}/delete`} class="mt-5" onsubmit={(event) => void protectForm(event, 'auth.recovery', `user:${String(user.id)}/delete/actor:${actorId}`, `Konto ${text(user.username)} endgültig löschen?`)}><input type="hidden" name="__csrf" value={csrf}><button class="btn preset-tonal-error" type="submit">Recovery: Konto löschen</button></form>
        {/if}
      </section>
    {/each}
  </div>
</AppShell>
