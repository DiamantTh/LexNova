<script lang="ts">
  import type { BootstrapData } from '../bootstrap';
  import AppShell from '../components/AppShell.svelte';
  import NoticeList from '../components/NoticeList.svelte';
  import { records, strings, text, number } from '../lib/data';
  import { passkeysSupported, registerPasskey } from '../lib/webauthn';
  let { data }: { data: BootstrapData } = $props();
  const csrf = $derived(text(data.csrfToken));
  const userId = $derived(number(data.currentUserId));
  const passkeys = $derived(records(data.currentPasskeys));
  const totpKeys = $derived(records(data.currentTotpKeys));
  let label = $state('Mein Passkey');
  let status = $state('');
  let pending = $state(false);
  const supported = passkeysSupported();
  async function register(): Promise<void> { pending = true; status = 'Passkey wird angefordert …'; try { window.location.assign(await registerPasskey(userId, label, csrf)); } catch (error) { status = error instanceof Error ? error.message : 'Registrierung fehlgeschlagen.'; pending = false; } }
  const remove = (event: SubmitEvent, name: string) => { if (!confirm(`${name} wirklich löschen?`)) event.preventDefault(); };
</script>

<AppShell title="Anmeldesicherheit" area="account" csrfToken={csrf}>
  <NoticeList errors={strings(data.errors)} messages={strings(data.messages)} />
  <div class="content-grid">
    <section class="card preset-filled-surface-100-900 p-6"><h2 class="h2">Passkey hinzufügen</h2><p class="mt-2 opacity-75">Plattform-Passkeys und externe FIDO2-Hardware-Schlüssel werden unterstützt. Der Name lässt sich später ändern.</p><div class="mt-5 flex flex-wrap items-end gap-3"><label class="label grow"><span>Name</span><input class="input" bind:value={label} maxlength="100"></label><button class="btn preset-filled-primary-500" type="button" onclick={register} disabled={!supported || pending}>{pending ? 'Warte auf Authenticator …' : 'Passkey registrieren'}</button></div><p class="mt-3 text-sm opacity-70" role="status">{status || (supported ? 'Passkey-Unterstützung erkannt.' : 'Dieser Browser unterstützt keine Passkeys.')}</p></section>
    <section class="card preset-filled-surface-100-900 p-6"><h2 class="h2">TOTP</h2><p class="mt-2 opacity-75">Aktive Schlüssel: {totpKeys.length}. LexNova verwendet SHA-256 und achtstellige Codes.</p><a class="btn preset-tonal-primary mt-5" href="/admin/totp/enroll">Weiteren TOTP-Schlüssel einrichten</a></section>
  </div>
  <section class="card preset-filled-surface-100-900 mt-6 p-6"><h2 class="h2">Meine Passkeys</h2>{#if passkeys.length === 0}<p class="mt-3 opacity-70">Noch kein Passkey registriert.</p>{:else}<div class="credential-grid mt-5">{#each passkeys as passkey}<article class="credential-card"><h3 class="h3">{text(passkey.label, 'Passkey')}</h3><p class="status-pill status-good mt-2">{text(passkey.kind, 'Passkey')}</p><dl class="detail-list"><div><dt>Einordnung</dt><dd>{text(passkey.attachment, 'nicht gemeldet')}</dd></div><div><dt>Transporte</dt><dd>{Array.isArray(passkey.transports) && passkey.transports.length ? passkey.transports.join(', ') : 'nicht gemeldet'}</dd></div><div><dt>Speicher</dt><dd>{passkey.backup_eligible === true ? 'synchronisierbar' : passkey.backup_eligible === false ? 'gerätegebunden' : 'nicht gemeldet'}</dd></div><div><dt>Hersteller</dt><dd>{text(passkey.manufacturer, 'nicht zuverlässig ermittelbar')}</dd></div>{#if passkey.aaguid}<div><dt>AAGUID</dt><dd><code>{text(passkey.aaguid)}</code></dd></div>{/if}</dl><form method="post" action={`/admin/users/${userId}/passkeys/${String(passkey.id)}/update`} class="mt-4 flex gap-2"><input type="hidden" name="__csrf" value={csrf}><input class="input" name="label" value={text(passkey.label)} maxlength="100" required><button class="btn preset-tonal" type="submit">Umbenennen</button></form><form method="post" action={`/admin/users/${userId}/passkeys/${String(passkey.id)}/delete`} class="mt-2" onsubmit={(event) => remove(event, text(passkey.label, 'Passkey'))}><input type="hidden" name="__csrf" value={csrf}><button class="btn preset-tonal-error" type="submit">Löschen</button></form></article>{/each}</div>{/if}</section>
</AppShell>
