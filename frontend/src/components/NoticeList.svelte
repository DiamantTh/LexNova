<script lang="ts">
  let { errors = [], messages = [] }: { errors?: string[]; messages?: string[] } = $props();
</script>

{#each errors as message}<div class="alert alert-error mb-3" role="alert"><span>{message}</span></div>{/each}
{#each messages as message}<div class="alert alert-success mb-3" role="status"><span>{message}</span></div>{/each}
