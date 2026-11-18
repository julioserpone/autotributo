<script setup lang="ts">
import { computed } from 'vue';

import { type SyncRun, useSyncStore } from '@/stores/sync';

const props = defineProps<{
    run: SyncRun;
}>();

const syncStore = useSyncStore();

const isActive = computed(() => syncStore.activeStatuses.includes(props.run.status));

/**
 * Comprobantes procesados del run. Los `skipped` y `failed` cuentan igual que
 * los `imported`/`updated`: ya se intentaron y no quedan pendientes.
 */
const processed = computed(() => Math.min(
    props.run.totals.found,
    (props.run.totals.imported ?? 0)
        + (props.run.totals.updated ?? 0)
        + (props.run.totals.skipped ?? 0)
        + (props.run.totals.failed ?? 0),
));
</script>

<template>
  <div>
    <UBadge
      :color="syncStore.statusColor(run.status)"
      variant="subtle"
      size="sm"
      class="gap-1.5 font-medium"
    >
      <span
        v-if="isActive"
        class="size-1.5 animate-pulse rounded-full bg-current"
      />
      {{ syncStore.statusLabel(run.status) }}
    </UBadge>
    <span class="font-mono text-xs tabular-nums text-muted">
      {{ processed }}/{{ run.totals.found }}
    </span>
  </div>
</template>
