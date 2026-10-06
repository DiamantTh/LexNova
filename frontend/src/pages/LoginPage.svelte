<script lang="ts">
  import type { BootstrapData } from '../bootstrap';
  import NoticeList from '../components/NoticeList.svelte';
  import PageFrame from '../components/PageFrame.svelte';
  import { strings, text, translator } from '../lib/data';
  import { loginWithPasskey, passkeysSupported } from '../lib/webauthn';

  let { data }: { data: BootstrapData } = $props();
  const t = $derived(translator(data));
  const csrfToken = $derived(text(data.csrfToken));
  const errors = $derived(strings(data.errors));
  const messages = $derived(strings(data.messages));
  let passkeyStatus = $state('');
  let pending = $state(false);
  let username = $state('');
  const supported = passkeysSupported();

  async function passkeyLogin(mode: 'primary' | 'mfa' = 'primary'): Promise<void> {
    if (mode === 'primary' && username.trim() === '') {
      passkeyStatus = 'Bitte zuerst den Benutzernamen eingeben.';
      return;
    }
    pending = true;
    passkeyStatus = 'FIDO2-Anmeldung wird angefordert …';
    try {
      window.location.assign(await loginWithPasskey(username.trim(), csrfToken, mode));
    } catch (error) {
      passkeyStatus = error instanceof Error ? error.message : 'Passkey-Anmeldung fehlgeschlagen.';
      pending = false;
    }
  }
</script>

<PageFrame title={t('Admin Login')} narrow>
  <NoticeList {errors} {messages} />
  <section class="card preset-filled-surface-100-900 p-6 shadow-xl">
    <h2 class="h2">Mit FIDO2 anmelden</h2>
    <p class="mt-2 opacity-75">Benutzername eingeben. Danach bietet LexNova nur die registrierten Schlüssel dieses Kontos an.</p>
    <label class="label mt-5"><span>{t('Username')}</span><input class="input" type="text" bind:value={username} autocomplete="username" required maxlength="100"></label>
    <button class="btn preset-filled-primary-500 mt-4 w-full" type="button" onclick={() => passkeyLogin()} disabled={!supported || pending || username.trim() === ''}>{pending ? 'FIDO2-Schlüssel wird geöffnet …' : 'Mit Passkey / Sicherheitsschlüssel anmelden'}</button>
    <p class="mt-3 text-sm opacity-70" role="status">{passkeyStatus || (supported ? 'FIDO2-Passkey oder -Sicherheitsschlüssel verwenden.' : 'Dieser Browser unterstützt kein WebAuthn.')}</p>
    <details class="mt-7 border-t border-surface-300-700 pt-5">
      <summary class="cursor-pointer font-semibold">Alternativ mit Passwort anmelden</summary>
      <form method="post" action="/admin/login" class="mt-5 grid gap-4">
        <input type="hidden" name="__csrf" value={csrfToken}>
        <label class="label"><span>{t('Username')}</span><input class="input" type="text" name="username" autocomplete="username" required maxlength="100"></label>
        <label class="label"><span>{t('Password')}</span><input class="input" type="password" name="password" autocomplete="current-password" required maxlength="256"></label>
        <button class="btn preset-tonal-primary" type="submit">{t('Sign in')}</button>
      </form>
    </details>
  </section>
</PageFrame>
