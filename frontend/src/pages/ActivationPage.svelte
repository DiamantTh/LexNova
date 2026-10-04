<script lang="ts">
  import type { BootstrapData } from '../bootstrap';
  import NoticeList from '../components/NoticeList.svelte';
  import PageFrame from '../components/PageFrame.svelte';
  import { text, strings } from '../lib/data';
  import { activateWithPasskey, passkeysSupported } from '../lib/webauthn';

  let { data }: { data: BootstrapData } = $props();
  const csrf = $derived(text(data.csrfToken));
  const verified = $derived(data.verified === true);
  let label = $state('Mein Sicherheitsschlüssel');
  let status = $state('');
  let pending = $state(false);
  async function activate(mode: 'hardware' | 'any'): Promise<void> {
    pending = true;
    status = 'FIDO2-Aktivierung wird vorbereitet …';
    try { window.location.assign(await activateWithPasskey(label, csrf, mode)); }
    catch (error) { status = error instanceof Error ? error.message : 'Aktivierung fehlgeschlagen.'; pending = false; }
  }
</script>

<PageFrame title="Konto mit FIDO2 aktivieren" narrow>
  <NoticeList errors={strings(data.errors)} />
  {#if !verified}
    <section class="card preset-filled-surface-100-900 p-6 shadow-xl">
      <p class="opacity-75">Gib das einmalige Aktivierungs- oder Recovery-Ticket ein, das dir sicher übermittelt wurde.</p>
      <form method="post" action="/activate/verify" class="mt-5 grid gap-4">
        <input type="hidden" name="__csrf" value={csrf}>
        <label class="label"><span>Einmalcode</span><input class="input font-mono" name="ticket" required maxlength="64" autocomplete="one-time-code" autocapitalize="none" spellcheck="false"></label>
        <button class="btn preset-filled-primary-500" type="submit">Ticket prüfen</button>
      </form>
    </section>
  {:else}
    <section class="card preset-filled-surface-100-900 p-6 shadow-xl">
      <p class="opacity-75">Konto <strong>{text(data.username)}</strong>. Der Ticketinhaber richtet jetzt den ersten eigenen FIDO2-Schlüssel ein.</p>
      <label class="label mt-5"><span>Name</span><input class="input" bind:value={label} maxlength="100"></label>
      <button class="btn preset-filled-primary-500 mt-5 w-full" type="button" onclick={() => activate('hardware')} disabled={!passkeysSupported() || pending || data.passkeysAvailable !== true}>{pending ? 'Warte auf Authenticator …' : 'FIDO2-Sicherheitsschlüssel hinzufügen · empfohlen'}</button>
      <button class="btn preset-tonal-primary mt-3 w-full" type="button" onclick={() => activate('any')} disabled={!passkeysSupported() || pending || data.passkeysAvailable !== true}>Passkey / anderes Gerät hinzufügen</button>
      <p class="mt-3 text-sm opacity-70" role="status">{status || 'User Verification ist erforderlich. Das Ticket verfällt nach 24 Stunden und kann nur einmal verwendet werden.'}</p>
    </section>
  {/if}
</PageFrame>
