<script lang="ts">
  import type { BootstrapData } from './bootstrap';

  let { bootstrap }: { bootstrap: BootstrapData } = $props();
</script>

<svelte:head>
  <title>{bootstrap.title ?? 'LexNova'}</title>
</svelte:head>

<main class="lexnova-shell">
  <section class="lexnova-card" aria-labelledby="page-title">
    <p class="text-sm opacity-70">LexNova · Svelte 5 · Skeleton 5</p>
    <h1 id="page-title" class="h2 mt-2">{bootstrap.title ?? 'LexNova'}</h1>
    <p class="mt-4">
      Der Frontend-Renderer ist bereit. Die Seite <code>{bootstrap.page}</code> wird schrittweise migriert.
    </p>
  </section>
</main>
