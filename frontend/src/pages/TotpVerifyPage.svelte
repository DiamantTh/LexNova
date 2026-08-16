<script lang="ts">
  import type { BootstrapData } from '../bootstrap';
  import NoticeList from '../components/NoticeList.svelte';
  import PageFrame from '../components/PageFrame.svelte';
  import { strings, text, translator } from '../lib/data';
  let { data }: { data: BootstrapData } = $props();
  const t = $derived(translator(data));
</script>

<PageFrame title={t('Two-Factor Authentication')} narrow>
  <NoticeList errors={strings(data.errors)} />
  <section class="card preset-filled-surface-100-900 p-6 shadow-xl">
    <p class="opacity-75">{t('Enter the 8-digit code from your authenticator app to complete sign-in.')}</p>
    <form method="post" action="/admin/totp/verify" autocomplete="off" class="mt-6 grid gap-5">
      <input type="hidden" name="__csrf" value={text(data.csrfToken)}>
      <label class="label"><span>{t('Authentication Code')}</span><input class="input text-center text-2xl tracking-[0.35em]" type="text" name="code" inputmode="numeric" pattern="[0-9]{8}" maxlength="8" required autocomplete="one-time-code"></label>
      <div class="flex items-center gap-4"><button class="btn preset-filled-primary-500" type="submit">{t('Verify')}</button><a class="anchor" href="/admin/login">{t('Cancel')}</a></div>
    </form>
  </section>
</PageFrame>
