<script lang="ts">
  import type { BootstrapData } from '../bootstrap';
  import NoticeList from '../components/NoticeList.svelte';
  import PageFrame from '../components/PageFrame.svelte';
  import { number, strings, text, translator } from '../lib/data';
  import { stepUp } from '../lib/webauthn';
  let { data }: { data: BootstrapData } = $props();
  const t = $derived(translator(data));
  const keyCount = $derived(number(data.existingKeyCount));
  const currentUserId = $derived(number(data.currentUserId));
  const limitReached = $derived(data.limitReached === true);
  const requiresStepUp = $derived(data.requiresStepUp === true);
  let status = $state('');
  async function protectEnrollment(event: SubmitEvent): Promise<void> {
    const form = event.currentTarget;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.dataset.stepUpReady === '1') { delete form.dataset.stepUpReady; return; }
    if (!requiresStepUp) return;
    event.preventDefault();
    try {
      await stepUp('auth.totp.add', `user:${currentUserId}`, text(data.csrfToken));
      form.dataset.stepUpReady = '1';
      form.requestSubmit();
    } catch (error) {
      status = error instanceof Error ? error.message : 'Bestätigung fehlgeschlagen.';
    }
  }
</script>

<PageFrame title={keyCount > 0 ? t('Add another TOTP key') : t('Two-Factor Authentication (TOTP)')} narrow>
  <NoticeList errors={strings(data.errors)} />
  {#if keyCount > 0}<p class="mb-5 opacity-75">Bereits {keyCount} Schlüssel. Der neue Schlüssel wird zusätzlich hinterlegt.</p>{/if}
  {#if limitReached}<aside class="alert alert-warning mb-5"><div>Das Limit von {number(data.totpLimit)} TOTP-Schlüsseln ist erreicht. Entferne einen bestehenden Schlüssel, bevor du einen weiteren einrichtest.</div></aside>{:else}
  <aside class="alert alert-warning mb-5"><div><strong>{t('App compatibility notice')}</strong><p class="mt-1">LexNova verwendet SHA-256 und achtstellige TOTP-Codes. Geeignet sind unter anderem Aegis, 2FAS, KeePassXC, Ente Auth und Bitwarden Authenticator.</p></div></aside>
  <section class="card preset-filled-surface-100-900 p-6 text-center shadow-xl">
    <h2 class="h3">{t('Scan QR Code')}</h2>
    <div class="qr-code mx-auto mt-4" aria-label="QR-Code zur TOTP-Einrichtung">{@html text(data.qrSvg)}</div>
    <p class="mt-5 text-sm opacity-75">{t('Manual entry (Base32 secret):')}</p>
    <code class="mt-1 block select-all break-all text-base">{text(data.secret)}</code>
    <details class="mt-4 text-left"><summary class="cursor-pointer">{t('Copy provisioning URI')}</summary><pre class="mt-2 overflow-auto rounded bg-surface-200-800 p-3 text-xs whitespace-pre-wrap break-all">{text(data.uri)}</pre></details>
  </section>
  <section class="card preset-filled-surface-100-900 mt-5 p-6 shadow-xl">
    <form method="post" action="/admin/totp/enroll" autocomplete="off" class="grid gap-5" onsubmit={(event) => void protectEnrollment(event)}>
      <input type="hidden" name="__csrf" value={text(data.csrfToken)}>
      <label class="label"><span>{t('Key label (e.g. Phone, YubiKey)')}</span><input class="input" name="label" value="Default" maxlength="100" autocomplete="off"><small>{t('A name to identify this key among multiple keys.')}</small></label>
      <label class="label"><span>{t('Authentication Code')}</span><input class="input text-center text-2xl tracking-[0.35em]" name="code" inputmode="numeric" pattern="[0-9]{8}" maxlength="8" required autocomplete="one-time-code"></label>
      <div class="flex items-center gap-4"><button class="btn preset-filled-primary-500" type="submit">{t('Enroll')}</button><a class="anchor" href="/user/security">{t('Cancel')}</a></div>
      <p class="text-sm opacity-70" role="status">{status}</p>
    </form>
  </section>
  {/if}
</PageFrame>
