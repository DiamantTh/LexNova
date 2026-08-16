<script lang="ts">
  import type { BootstrapData } from '../bootstrap';
  import { record, text, translator } from '../lib/data';
  let { data }: { data: BootstrapData } = $props();
  const t = $derived(translator(data));
  const entity = $derived(record(data.entity));
  const document = $derived(record(data.document));
  const variants = $derived(record(data.variants));
  const documentType = $derived(text(data.documentType, 'imprint'));
  const heading = $derived(documentType === 'privacy' ? t('Privacy Policy') : t('Imprint'));
  const canonicalUrl = $derived(text(data.canonicalUrl));
</script>

<svelte:head>
  <title>{heading} · LexNova</title><meta name="robots" content="index, follow">
  {#if canonicalUrl}<link rel="canonical" href={canonicalUrl}>{/if}
  {#each Object.entries(variants) as [language, url]}{#if typeof url === 'string'}<link rel="alternate" hreflang={language} href={url}>{/if}{/each}
</svelte:head>

<a class="skip-link" href="#main-content">{t('Skip to main content')}</a>
<main id="main-content" class="public-document min-h-screen p-5 md:p-12">
  <article class="mx-auto max-w-4xl">
    <header class="mb-8">
      <p class="text-sm uppercase tracking-[0.18em] opacity-65">{t('LexNova Legal Documents')}</p><h1 class="mt-2 font-serif text-4xl font-bold">{heading}</h1>
      {#if Object.keys(variants).length > 1}<nav class="mt-4 flex flex-wrap gap-3" aria-label={t('Language')}>{#each Object.entries(variants) as [language, url]}{#if language === text(document.language)}<strong aria-current="true">{language}</strong>{:else if typeof url === 'string'}<a class="anchor" href={url}>{language}</a>{/if}{/each}</nav>{/if}
    </header>
    <section class="card bg-white p-6 text-slate-900 shadow-xl md:p-9">
      <h2 class="document-section">{t('Contact')}</h2><div class="whitespace-pre-wrap leading-7">{text(entity.contact_data)}</div>
      <h2 class="document-section">{t('Document')}</h2><div class="whitespace-pre-wrap leading-7">{text(document.content)}</div>
      <p class="mt-8 text-sm opacity-65">Version {String(document.version ?? '')} · {text(document.updated_at)}</p>
    </section>
  </article>
</main>
