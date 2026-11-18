<script setup lang="ts">
import { computed } from 'vue';

import { type SyncRun, useSyncStore } from '@/stores/sync';

const props = defineProps<{
    run: SyncRun;
}>();

const syncStore = useSyncStore();

const isActive = computed(() => syncStore.activeStatuses.includes(props.run.status));

/**
 * Comprobantes guardados. Los `updated` cuentan igual que los `imported`:
 * una re-descarga forzada actualiza el comprobante en vez de crearlo.
 */
const stored = computed(() => (props.run.totals.imported ?? 0) + (props.run.totals.updated ?? 0));
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
      {{ stored }}/{{ run.totals.found }}
    </span>
  </div>
</template>
