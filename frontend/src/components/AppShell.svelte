<script lang="ts">
  import type { Snippet } from 'svelte';
  let { title, area, csrfToken, children }: { title: string; area: string; csrfToken: string; children: Snippet } = $props();
  const links = [
    ['/verwaltung', 'Verwaltung', 'workspace'],
    ['/user/security', 'Mein Konto', 'account'],
    ['/admin', 'Administration', 'admin'],
  ];
</script>

<svelte:head><title>{title} · LexNova</title></svelte:head>
<a class="skip-link" href="#main-content">Zum Hauptinhalt springen</a>
<div class="app-layout">
  <aside class="app-sidebar">
    <a class="brand" href="/verwaltung">LexNova</a>
    <nav aria-label="Hauptnavigation">
      {#each links as link}<a class:active={area === link[2]} href={link[0]}>{link[1]}</a>{/each}
    </nav>
    <form method="post" action="/admin/logout"><input type="hidden" name="__csrf" value={csrfToken}><button class="btn preset-tonal" type="submit">Abmelden</button></form>
  </aside>
  <main id="main-content" class="app-content">
    <header class="page-heading"><p class="text-sm uppercase tracking-[.18em] opacity-65">{area === 'admin' ? 'Instanzverwaltung' : area === 'account' ? 'Benutzerkonto' : 'Rechtstexte'}</p><h1 class="h1">{title}</h1></header>
    {@render children()}
  </main>
</div>
