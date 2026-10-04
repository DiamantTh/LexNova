<script lang="ts">
  import type { BootstrapData } from '../bootstrap';
  import NoticeList from '../components/NoticeList.svelte';
  import PageFrame from '../components/PageFrame.svelte';
  import { strings, text, translator } from '../lib/data';
  import { loginWithPasskey, passkeysSupported } from '../lib/webauthn';
  let { data }: { data: BootstrapData } = $props();
  const t = $derived(translator(data));
  let status = $state('');
  let pending = $state(false);
  async function passkeyLogin(): Promise<void> {
    pending = true;
    status = 'FIDO2 wird angefordert …';
    try { window.location.assign(await loginWithPasskey('', text(data.csrfToken), 'mfa')); }
    catch (error) { status = error instanceof Error ? error.message : 'FIDO2-Anmeldung fehlgeschlagen.'; pending = false; }
  }
</script>

<PageFrame title={t('Two-Factor Authentication')} narrow>
  <NoticeList errors={strings(data.errors)} />
  <section class="card preset-filled-surface-100-900 p-6 shadow-xl">
    {#if data.passkeyAvailable === true}
      <p class="opacity-75">Das Passwort wurde bestätigt. Mit FIDO2 fortfahren oder TOTP als Alternative verwenden.</p>
      <button class="btn preset-filled-primary-500 mt-5 w-full" type="button" onclick={passkeyLogin} disabled={!passkeysSupported() || pending}>{pending ? 'FIDO2 wird geöffnet …' : 'Mit Passkey / Sicherheitsschlüssel fortfahren'}</button>
      <p class="mt-2 text-sm opacity-70" role="status">{status}</p>
    {:else}
      <p class="opacity-75">{t('Enter the 8-digit code from your authenticator app to complete sign-in.')}</p>
    {/if}
    <details class="mt-5 border-t border-surface-300-700 pt-4"><summary class="cursor-pointer font-semibold">{data.passkeyAvailable === true ? 'Alternativ mit TOTP' : 'TOTP-Code eingeben'}</summary>
      <form method="post" action="/admin/totp/verify" autocomplete="off" class="mt-6 grid gap-5">
        <input type="hidden" name="__csrf" value={text(data.csrfToken)}>
        <label class="label"><span>{t('Authentication Code')}</span><input class="input text-center text-2xl tracking-[0.35em]" type="text" name="code" inputmode="numeric" pattern="[0-9]{8}" maxlength="8" required autocomplete="one-time-code"></label>
        <div class="flex items-center gap-4"><button class="btn preset-filled-primary-500" type="submit">{t('Verify')}</button><a class="anchor" href="/admin/login">{t('Cancel')}</a></div>
      </form>
    </details>
  </section>
</PageFrame>
