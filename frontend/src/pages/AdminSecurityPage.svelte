<script lang="ts">
  import type { BootstrapData } from '../bootstrap';
  import AppShell from '../components/AppShell.svelte';
  import NoticeList from '../components/NoticeList.svelte';
  import { flag, number, record, strings, text } from '../lib/data';
  import { stepUp } from '../lib/webauthn';

  let { data }: { data: BootstrapData } = $props();
  const csrf = $derived(text(data.csrfToken));
  const fail2ban = $derived(record(data.fail2ban));
  const limits = $derived(record(data.authLimits));
  let status = $state('');
  async function protectLimits(event: SubmitEvent): Promise<void> {
    const form = event.currentTarget;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.dataset.stepUpReady === '1') { delete form.dataset.stepUpReady; return; }
    event.preventDefault();
    try {
      await stepUp('auth.policy.change', 'instance:auth-limits', csrf);
      form.dataset.stepUpReady = '1';
      form.requestSubmit();
    } catch (error) { status = error instanceof Error ? error.message : 'Bestätigung fehlgeschlagen.'; }
  }
  async function protectFail2Ban(event: SubmitEvent): Promise<void> {
    const form = event.currentTarget;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.dataset.stepUpReady === '1') { delete form.dataset.stepUpReady; return; }
    event.preventDefault();
    try {
      await stepUp('auth.policy.change', 'instance:fail2ban', csrf);
      form.dataset.stepUpReady = '1';
      form.requestSubmit();
    } catch (error) { status = error instanceof Error ? error.message : 'Bestätigung fehlgeschlagen.'; }
  }
</script>

<AppShell title="Instanzsicherheit" area="admin" csrfToken={csrf}>
  <NoticeList errors={strings(data.errors)} messages={strings(data.messages)} />
  <nav class="subnav"><a href="/admin">Übersicht</a><a class="active" href="/admin/security">Sicherheit</a><a href="/admin/system">System</a></nav>

  <section class="card preset-filled-surface-100-900 mt-6 p-6">
    <h2 class="h2">Authentifizierungs-Limits</h2>
    <p class="mt-2 opacity-75">Limits gelten pro Benutzer. Das Hard-Limit beträgt 100. Eine Absenkung löscht keine vorhandenen Credentials; weitere Registrierungen bleiben gesperrt, bis der Bestand unter dem Limit liegt.</p>
    <form method="post" action="/admin/security/auth-limits" class="form-grid mt-5" onsubmit={(event) => void protectLimits(event)}>
      <input type="hidden" name="__csrf" value={csrf}>
      <label class="label"><span>WebAuthn-Credentials pro Benutzer</span><input class="input" type="number" name="webauthn_limit" min="1" max="100" required value={number(limits.webauthn, 10)}></label>
      <label class="label"><span>TOTP-Credentials pro Benutzer</span><input class="input" type="number" name="totp_limit" min="1" max="100" required value={number(limits.totp, 5)}></label>
      <button class="btn preset-filled-primary-500" type="submit">Limits speichern</button>
    </form>
    <p class="mt-3 text-sm opacity-70" role="status">{status}</p>
  </section>

  <section class="card preset-filled-surface-100-900 mt-6 p-6">
    <h2 class="h2">Fail2ban-Signallog</h2>
    <div class="mt-4 flex flex-wrap gap-3"><span class:status-good={flag(fail2ban.enabled)} class:status-warn={!flag(fail2ban.enabled)} class="status-pill">{flag(fail2ban.enabled) ? 'aktiv' : 'inaktiv'}</span><span class:status-good={flag(fail2ban.writable)} class:status-bad={!flag(fail2ban.writable)} class="status-pill">{flag(fail2ban.writable) ? 'schreibbar' : 'nicht schreibbar'}</span></div>
    <p class="mt-4 opacity-75">Quelle: {text(fail2ban.source) === 'database' ? 'Datenbank' : 'config.toml'} · Pfad: <code>{text(fail2ban.path)}</code></p>
    <p class="mt-2 text-sm opacity-70">Das Signallog enthält nur Zeitstempel, Kennung und IP – keine Benutzernamen oder Credentials.</p>
    <form method="post" action="/admin/security/fail2ban" class="mt-6 grid max-w-xl gap-4" onsubmit={(event) => void protectFail2Ban(event)}><input type="hidden" name="__csrf" value={csrf}><label class="label"><span>Aktivierung</span><select class="select" name="mode"><option value="config" selected={text(fail2ban.source) === 'config'}>config.toml verwenden</option><option value="enabled" selected={text(fail2ban.source) === 'database' && flag(fail2ban.enabled)}>In Datenbank aktivieren</option><option value="disabled" selected={text(fail2ban.source) === 'database' && !flag(fail2ban.enabled)}>In Datenbank deaktivieren</option></select></label><button class="btn preset-filled-primary-500" type="submit">Einstellung speichern</button></form>
  </section>
</AppShell>
