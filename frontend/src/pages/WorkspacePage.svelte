<script lang="ts">
  import type { BootstrapData } from '../bootstrap';
  import AppShell from '../components/AppShell.svelte';
  import NoticeList from '../components/NoticeList.svelte';
  import { record, records, strings, text } from '../lib/data';
  let { data }: { data: BootstrapData } = $props();
  const csrf = $derived(text(data.csrfToken));
  const entities = $derived(records(data.entities));
  const documents = $derived(records(data.documents));
  const editEntity = $derived(record(data.editEntity));
  const editDocument = $derived(record(data.editDocument));
  const section = $derived(text(data.section, 'overview'));
  const entityName = (id: unknown) => text(entities.find((entity) => Number(entity.id) === Number(id))?.name, `#${String(id)}`);
  const remove = (event: SubmitEvent, label: string) => { if (!confirm(`${label} wirklich löschen?`)) event.preventDefault(); };
</script>

<AppShell title={section === 'entities' ? 'Betreiber verwalten' : section === 'documents' ? 'Dokumente verwalten' : 'Übersicht'} area="workspace" csrfToken={csrf}>
  <NoticeList errors={strings(data.errors)} messages={strings(data.messages)} />
  <nav class="subnav" aria-label="Verwaltung"><a class:active={section === 'overview'} href="/verwaltung">Übersicht</a><a class:active={section === 'entities'} href="/verwaltung/entities">Betreiber</a><a class:active={section === 'documents'} href="/verwaltung/documents">Dokumente</a></nav>
  {#if section === 'overview'}
    <div class="metric-grid"><a class="metric-card" href="/verwaltung/entities"><strong>{entities.length}</strong><span>Betreiber</span></a><a class="metric-card" href="/verwaltung/documents"><strong>{documents.length}</strong><span>Rechtstexte</span></a></div>
    <section class="card preset-filled-surface-100-900 mt-6 p-6"><h2 class="h2">LexNova-Verwaltung</h2><p class="mt-2 opacity-75">Betreiber und die dazugehörigen Impressums- oder Datenschutztexte werden getrennt verwaltet. Jede Sprach- und Typvariante besitzt ihren eigenen öffentlichen Dokument-Hash.</p></section>
  {:else if section === 'entities'}
    <div class="content-grid">
      <section class="card preset-filled-surface-100-900 p-6"><h2 class="h2">{editEntity.id ? `Betreiber #${String(editEntity.id)} bearbeiten` : 'Betreiber anlegen'}</h2><form method="post" action={editEntity.id ? `/verwaltung/entities/${String(editEntity.id)}/edit` : '/verwaltung/entities/create'} class="mt-5 grid gap-4"><input type="hidden" name="__csrf" value={csrf}><label class="label"><span>Name / Organisation</span><input class="input" name="name" value={text(editEntity.name)} required maxlength="255"></label><label class="label"><span>Kontaktdaten</span><textarea class="textarea" name="contact_data" rows="8" required>{text(editEntity.contact_data)}</textarea></label><div class="flex gap-3"><button class="btn preset-filled-primary-500" type="submit">{editEntity.id ? 'Speichern' : 'Anlegen'}</button>{#if editEntity.id}<a class="btn preset-tonal" href="/verwaltung/entities">Abbrechen</a>{/if}</div></form></section>
      <section class="card preset-filled-surface-100-900 overflow-x-auto p-6"><h2 class="h2">Vorhandene Betreiber</h2><table class="table mt-4"><thead><tr><th>ID</th><th>Name</th><th>Hash</th><th>Aktionen</th></tr></thead><tbody>{#each entities as entity}<tr><td>{String(entity.id)}</td><td>{text(entity.name)}</td><td><code>{text(entity.hash)}</code></td><td><div class="actions-row"><a class="anchor" href={`/verwaltung/entities/${String(entity.id)}/edit`}>Bearbeiten</a><form method="post" action={`/verwaltung/entities/${String(entity.id)}/delete`} onsubmit={(event) => remove(event, text(entity.name))}><input type="hidden" name="__csrf" value={csrf}><button class="btn preset-tonal-error" type="submit">Löschen</button></form></div></td></tr>{/each}</tbody></table></section>
    </div>
  {:else}
    <div class="content-grid">
      <section class="card preset-filled-surface-100-900 p-6"><h2 class="h2">{editDocument.id ? `Dokument #${String(editDocument.id)} bearbeiten` : 'Dokument anlegen'}</h2><form method="post" action={editDocument.id ? `/verwaltung/documents/${String(editDocument.id)}/edit` : '/verwaltung/documents/create'} class="mt-5 grid gap-4"><input type="hidden" name="__csrf" value={csrf}><label class="label"><span>Betreiber</span><select class="select" name="entity_id" required>{#each entities as entity}<option value={String(entity.id)} selected={Number(editDocument.entity_id) === Number(entity.id)}>{text(entity.name)}</option>{/each}</select></label><div class="form-grid"><label class="label"><span>Typ</span><select class="select" name="type" required><option value="imprint" selected={text(editDocument.type, 'imprint') === 'imprint'}>Impressum</option><option value="privacy" selected={text(editDocument.type) === 'privacy'}>Datenschutz</option></select></label><label class="label"><span>Sprache</span><input class="input" name="language" value={text(editDocument.language, 'de')} required maxlength="20"></label><label class="label"><span>Version</span><input class="input" name="version" value={text(editDocument.version)} required maxlength="50"></label></div><label class="label"><span>Inhalt</span><textarea class="textarea" name="content" rows="16" required>{text(editDocument.content)}</textarea></label><div class="flex gap-3"><button class="btn preset-filled-primary-500" type="submit">{editDocument.id ? 'Speichern' : 'Anlegen'}</button>{#if editDocument.id}<a class="btn preset-tonal" href="/verwaltung/documents">Abbrechen</a>{/if}</div></form></section>
      <section class="card preset-filled-surface-100-900 overflow-x-auto p-6"><h2 class="h2">Vorhandene Dokumente</h2><table class="table mt-4"><thead><tr><th>Betreiber</th><th>Typ</th><th>Sprache</th><th>Version</th><th>Aktionen</th></tr></thead><tbody>{#each documents as document}<tr><td>{entityName(document.entity_id)}</td><td>{text(document.type)}</td><td>{text(document.language)}</td><td>{text(document.version)}</td><td><div class="actions-row"><a class="anchor" href={`/out.php?typ=${encodeURIComponent(text(document.type))}&hash=${encodeURIComponent(text(document.public_hash))}`} target="_blank" rel="noopener">Anzeigen</a><a class="anchor" href={`/verwaltung/documents/${String(document.id)}/edit`}>Bearbeiten</a><form method="post" action={`/verwaltung/documents/${String(document.id)}/delete`} onsubmit={(event) => remove(event, `Dokument #${String(document.id)}`)}><input type="hidden" name="__csrf" value={csrf}><button class="btn preset-tonal-error" type="submit">Löschen</button></form></div></td></tr>{/each}</tbody></table></section>
    </div>
  {/if}
</AppShell>
