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
  let passkeyStatus = $state('');
  let pending = $state(false);
  const supported = passkeysSupported();

  async function passkeyLogin(): Promise<void> {
    pending = true;
    passkeyStatus = 'Passkey wird angefordert …';
    try {
      window.location.assign(await loginWithPasskey(csrfToken));
    } catch (error) {
      passkeyStatus = error instanceof Error ? error.message : 'Passkey-Anmeldung fehlgeschlagen.';
      pending = false;
    }
  }
</script>

<PageFrame title={t('Admin Login')} narrow>
  <NoticeList {errors} />
  <section class="card preset-filled-surface-100-900 p-6 shadow-xl">
    <h2 class="h2">Passkey-first</h2>
    <p class="mt-2 opacity-75">Passkey oder FIDO2-Sicherheitsschlüssel verwenden – ohne übertragbares Passwort.</p>
    <button class="btn preset-filled-primary-500 mt-5 w-full" type="button" onclick={passkeyLogin} disabled={!supported || pending}>{pending ? 'Passkey wird geöffnet …' : 'Mit Passkey anmelden'}</button>
    <p class="mt-3 text-sm opacity-70" role="status">{passkeyStatus || (supported ? 'Passkey-Unterstützung erkannt.' : 'Dieser Browser unterstützt keine Passkeys.')}</p>
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
