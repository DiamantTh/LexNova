<script lang="ts">
  import type { BootstrapData } from '../bootstrap';
  import PageFrame from '../components/PageFrame.svelte';
  import { text } from '../lib/data';
  let { data }: { data: BootstrapData } = $props();
</script>

<PageFrame title="Seite nicht gefunden" eyebrow="Fehler 404" narrow>
  <section class="card preset-filled-surface-100-900 p-6 shadow-xl">
    <h2 class="h2">Hier gibt es nichts zu finden.</h2><p class="mt-3 opacity-75">Die angeforderte Seite existiert nicht, wurde verschoben oder der Dokument-Link ist nicht mehr gültig.</p>
    {#if text(data.path)}<code class="mt-4 block break-all rounded bg-surface-200-800 p-3">{text(data.path)}</code>{/if}
    <a class="btn preset-filled-primary-500 mt-6" href="/admin">Zu LexNova</a>
  </section>
</PageFrame>
