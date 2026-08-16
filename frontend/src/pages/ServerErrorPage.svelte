<script lang="ts">
  import type { BootstrapData } from '../bootstrap';
  import PageFrame from '../components/PageFrame.svelte';
  import { text } from '../lib/data';
  let { data }: { data: BootstrapData } = $props();
</script>

<PageFrame title="Serverfehler" eyebrow="Fehler 500" narrow>
  <section class="card preset-filled-surface-100-900 p-6 shadow-xl">
    <h2 class="h2">LexNova konnte die Anfrage nicht abschließen.</h2><p class="mt-3 opacity-75">{text(data.message, 'Es ist ein unerwarteter Fehler aufgetreten. Versuche es erneut oder wende dich an die Administration.')}</p>
    <div class="mt-6 flex flex-wrap gap-3"><a class="btn preset-filled-primary-500" href="/admin">Zu LexNova</a><button class="btn preset-tonal" type="button" onclick={() => window.location.reload()}>Erneut versuchen</button></div>
  </section>
</PageFrame>
