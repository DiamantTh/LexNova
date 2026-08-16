<script lang="ts">
  import type { BootstrapData } from './bootstrap';
  import InstallPage from './pages/InstallPage.svelte';
  import LoginPage from './pages/LoginPage.svelte';
  import NotFoundPage from './pages/NotFoundPage.svelte';
  import PublicDocumentPage from './pages/PublicDocumentPage.svelte';
  import ServerErrorPage from './pages/ServerErrorPage.svelte';
  import TotpEnrollPage from './pages/TotpEnrollPage.svelte';
  import TotpVerifyPage from './pages/TotpVerifyPage.svelte';

  let { bootstrap }: { bootstrap: BootstrapData } = $props();
</script>

{#if bootstrap.page === 'install'}
  <InstallPage data={bootstrap} />
{:else if bootstrap.page === 'login'}
  <LoginPage data={bootstrap} />
{:else if bootstrap.page === 'totp-verify'}
  <TotpVerifyPage data={bootstrap} />
{:else if bootstrap.page === 'totp-enroll'}
  <TotpEnrollPage data={bootstrap} />
{:else if bootstrap.page === 'public-document'}
  <PublicDocumentPage data={bootstrap} />
{:else if bootstrap.page === 'not-found'}
  <NotFoundPage data={bootstrap} />
{:else if bootstrap.page === 'server-error'}
  <ServerErrorPage data={bootstrap} />
{:else}
  <ServerErrorPage data={{ ...bootstrap, status: 500, message: `Unbekannte Seite: ${bootstrap.page}` }} />
{/if}
