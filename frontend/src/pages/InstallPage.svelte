<script lang="ts">
  import type { BootstrapData } from '../bootstrap';
  import NoticeList from '../components/NoticeList.svelte';
  import PageFrame from '../components/PageFrame.svelte';
  import { flag, record, records, strings, text } from '../lib/data';

  let { data }: { data: BootstrapData } = $props();
  const prereq = $derived(record(data.prerequisites));
  const checks = $derived(records(prereq.checks));
  const cacheSupport = $derived(record(data.cacheSupport));
  const form = $derived(record(data.formData));
  const step = $derived(text(data.step, 'unlock'));
  const csrfToken = $derived(text(data.csrfToken));
  let databaseType = $derived(text(form.dbType, 'sqlite'));
  let cacheAdapter = $derived(text(form.cacheAdapter, 'filesystem'));
  const blocked = $derived(flag(prereq.blocked));
  const localePattern = '[a-zA-Z]{2,8}(-[a-zA-Z0-9]{1,8})*';
  function value(name: string, fallback = ''): string { return text(form[name], fallback); }
  function cacheAvailable(name: string): boolean { return flag(record(cacheSupport[name]).available); }
  function cacheReason(name: string): string { return text(record(cacheSupport[name]).reason); }
</script>

<PageFrame title="Installation" eyebrow="LexNova Setup">
  <NoticeList errors={strings(data.errors)} messages={strings(data.messages)} />
  {#if step === 'done'}
    <section class="card preset-filled-success-100-900 p-6 shadow-xl"><h2 class="h2">Installation abgeschlossen</h2><p class="mt-3">LexNova ist eingerichtet und der Installer wurde gesperrt.</p><a class="btn preset-filled-primary-500 mt-6" href="/admin/login">Zur Anmeldung</a></section>
  {:else}
    <section class="card preset-filled-surface-100-900 mb-6 overflow-x-auto p-6 shadow-xl">
      <h2 class="h2">Systemvoraussetzungen</h2>{#if blocked}<div class="alert alert-error mt-4">Mindestens eine Pflichtvoraussetzung fehlt. Die Installation bleibt gesperrt.</div>{/if}
      <table class="table mt-5 min-w-full"><thead><tr><th>Voraussetzung</th><th>Status</th><th>Wert</th></tr></thead><tbody>{#each checks as check}<tr><td>{text(check.label)}{flag(check.required) ? '' : ' *'}</td><td><span class:status-good={flag(check.ok) && !flag(check.fallback)} class:status-warn={flag(check.fallback) || !flag(check.required)} class:status-bad={!flag(check.ok) && flag(check.required)} class="status-pill">{flag(check.fallback) ? '⚠ Polyfill' : flag(check.ok) ? '✓ OK' : flag(check.required) ? '✗ Fehlt' : '⚠ Empfohlen'}</span></td><td><code>{text(check.value)}</code></td></tr>{/each}</tbody></table>
    </section>
    {#if step === 'unlock'}
      <section class="card preset-filled-surface-100-900 p-6 shadow-xl"><h2 class="h2">Installer entsperren</h2>{#if flag(data.installReady)}<form method="post" action="/install" class="mt-5 grid gap-5"><input type="hidden" name="__csrf" value={csrfToken}><label class="label"><span>Install-Passwort</span><input class="input" type="password" name="install_pw" required autocomplete="off" disabled={blocked}></label><button class="btn preset-filled-primary-500" type="submit" name="action" value="unlock" disabled={blocked}>Installer entsperren</button></form>{:else}<p class="mt-4">Install-Passwort wird initialisiert – bitte Seite neu laden.</p>{/if}</section>
    {:else}
      <section class="card preset-filled-surface-100-900 p-6 shadow-xl"><form method="post" action="/install" class="install-form">
        <input type="hidden" name="__csrf" value={csrfToken}><input type="hidden" name="action" value="install">
        <fieldset><legend>Install-Passwort</legend><label class="label"><span>Passwort</span><input class="input" type="password" name="install_pw" required autocomplete="off"></label></fieldset>
        <fieldset><legend>Datenbank</legend><div class="form-grid">
          <label class="label"><span>Öffentliche Basis-URL</span><input class="input" type="url" name="app_base_url" value={value('appBaseUrl')} required placeholder="https://legal.example.com" autocomplete="url"></label>
          <label class="label"><span>Typ</span><select class="select" name="db_type" bind:value={databaseType}><option value="sqlite">SQLite</option><option value="mysql">MySQL/MariaDB</option><option value="pgsql">PostgreSQL</option></select></label>
          {#if databaseType === 'sqlite'}<label class="label"><span>SQLite-Dateipfad</span><input class="input" name="db_path" value={value('dbPath')} required></label>{:else}
            <label class="label"><span>Host</span><input class="input" name="db_host" value={value('dbHost', 'localhost')} required></label><label class="label"><span>Datenbankname</span><input class="input" name="db_name" value={value('dbName')} required></label><label class="label"><span>Port (optional)</span><input class="input" name="db_port" value={value('dbPort')}></label><label class="label"><span>Benutzername</span><input class="input" name="db_user" value={value('dbUser')} required autocomplete="off"></label><label class="label"><span>Passwort</span><input class="input" type="password" name="db_password" autocomplete="new-password"></label>
          {/if}
        </div></fieldset>
        <fieldset><legend>Admin-Konto</legend><div class="form-grid"><label class="label"><span>Benutzername</span><input class="input" name="admin_username" value={value('adminUsername')} required autocomplete="off"></label><label class="label"><span>Passwort</span><input class="input" type="password" name="admin_password" required autocomplete="new-password"></label><label class="label"><span>Passwort bestätigen</span><input class="input" type="password" name="admin_password_confirm" required autocomplete="new-password"></label></div></fieldset>
        <fieldset><legend>Anwendung</legend><label class="label"><span>Standard-Sprache (BCP 47)</span><input class="input" name="app_locale" value={value('appLocale', 'de')} required pattern={localePattern}></label></fieldset>
        <fieldset><legend>Anwendungs-Cache</legend><p class="mb-4 opacity-75">Die Datenbank bleibt maßgeblich; der Cache enthält nur ersetzbare Kopien.</p><div class="form-grid">
          <label class="label"><span>Adapter</span><select class="select" name="cache_adapter" bind:value={cacheAdapter}><option value="filesystem">Dateisystem</option><option value="apcu" disabled={!cacheAvailable('apcu')}>APCu{cacheAvailable('apcu') ? '' : ' (nicht verfügbar)'}</option><option value="valkey" disabled={!cacheAvailable('valkey')}>Valkey{cacheAvailable('valkey') ? '' : ' (Client fehlt)'}</option></select><small>APCu: {cacheReason('apcu')} Valkey: {cacheReason('valkey')}</small></label>
          {#if cacheAdapter === 'valkey'}<label class="label"><span>Valkey-Host</span><input class="input" name="cache_host" value={value('cacheHost', '127.0.0.1')} required></label><label class="label"><span>Port</span><input class="input" type="number" name="cache_port" min="1" max="65535" value={value('cachePort', '6379')} required></label><label class="label"><span>Logische Datenbank</span><input class="input" type="number" name="cache_database" min="0" value={value('cacheDatabase', '0')}></label><label class="label"><span>ACL-Benutzername</span><input class="input" name="cache_username" value={value('cacheUsername')} autocomplete="off"></label><label class="label"><span>Passwort</span><input class="input" type="password" name="cache_password" autocomplete="new-password"></label><label class="flex items-center gap-3"><input class="checkbox" type="checkbox" name="cache_tls" value="1" checked={value('cacheTls') === '1'}> TLS verwenden</label>{/if}
        </div></fieldset>
        <fieldset><legend>Betreiber dieser Instanz</legend><div class="form-grid"><label class="label"><span>Name / Organisation</span><input class="input" name="operator_name" value={value('operatorName')} required autocomplete="organization"></label><label class="label"><span>Kontaktdaten</span><textarea class="textarea" name="operator_contact" required rows="5">{value('operatorContact')}</textarea></label></div></fieldset>
        <button class="btn preset-filled-primary-500" type="submit">Installieren</button>
      </form></section>
    {/if}
  {/if}
</PageFrame>
