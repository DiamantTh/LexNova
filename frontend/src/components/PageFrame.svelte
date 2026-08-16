<script lang="ts">
  import type { Snippet } from 'svelte';
  let { title, eyebrow = 'LexNova', narrow = false, children }: { title: string; eyebrow?: string; narrow?: boolean; children: Snippet } = $props();
</script>

<svelte:head><title>{title} · LexNova</title></svelte:head>
<a class="skip-link" href="#main-content">Zum Hauptinhalt springen</a>
<main id="main-content" class="min-h-screen bg-surface-50-950 p-4 md:p-8">
  <div class:page-narrow={narrow} class="page-container">
    <header class="mb-6"><p class="text-sm font-semibold uppercase tracking-[0.18em] text-primary-600-400">{eyebrow}</p><h1 class="h1 mt-1">{title}</h1></header>
    {@render children()}
  </div>
</main>
