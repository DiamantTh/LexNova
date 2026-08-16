<script lang="ts">
  import type { BootstrapData } from '../bootstrap'; import AppShell from '../components/AppShell.svelte'; import { records, text } from '../lib/data';
  let { data }: { data: BootstrapData } = $props(); const entries = $derived(records(data.auditLog));
</script>
<AppShell title="Audit-Protokoll" area="admin" csrfToken={text(data.csrfToken)}><nav class="subnav"><a href="/admin">Übersicht</a><a class="active" href="/admin/audit">Audit-Protokoll</a></nav><section class="card preset-filled-surface-100-900 mt-6 overflow-x-auto p-6"><table class="table"><thead><tr><th>Zeit</th><th>Handelnde Person</th><th>Aktion</th><th>Ziel</th><th>Detail</th><th>IP</th></tr></thead><tbody>{#each entries as entry}<tr><td>{text(entry.created_at)}</td><td>{text(entry.actor_name, '—')}</td><td><code>{text(entry.action)}</code></td><td><code>{text(entry.target, '—')}</code></td><td>{text(entry.detail)}</td><td>{text(entry.ip)}</td></tr>{/each}</tbody></table></section></AppShell>
