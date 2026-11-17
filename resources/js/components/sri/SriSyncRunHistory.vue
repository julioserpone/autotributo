<script setup lang="ts">
import { formatDocumentTypes, formatPeriods } from '@/lib/sri';
import { type SyncRun, useSyncStore } from '@/stores/sync';

defineProps<{
    runs: SyncRun[];
    loading: boolean;
    selectedRunId: string | null;
}>();

const emit = defineEmits<{
    select: [id: string];
}>();

const syncStore = useSyncStore();

function isActive(run: SyncRun): boolean {
    return syncStore.activeStatuses.includes(run.status);
}
</script>

<template>
  <section class="rounded-lg border border-line bg-surface shadow-2xs dark:shadow-none">
    <div class="border-b border-line px-4 py-3">
      <h2 class="text-sm font-medium text-ink">
        Historial de sincronizaciones
      </h2>
    </div>

    <div
      v-if="loading"
      class="px-4 py-6 text-center text-xs text-muted"
    >
      Cargando…
    </div>

    <ul
      v-else-if="runs.length > 0"
      class="divide-y divide-line"
    >
      <li
        v-for="run in runs"
        :key="run.id"
        class="flex cursor-pointer items-start justify-between gap-3 px-4 py-3 transition hover:bg-elevated/60"
        :class="{ 'bg-elevated/40': run.id === selectedRunId }"
        @click="emit('select', run.id)"
      >
        <div class="min-w-0 flex-1 space-y-0.5">
          <div class="flex items-center gap-2">
            <UIcon
              v-if="isActive(run)"
              name="i-lucide-loader-2"
              class="size-3.5 shrink-0 animate-spin text-info-ink"
            />
            <p class="truncate text-xs font-medium text-ink">
              Período {{ formatPeriods(run) }}
            </p>
          </div>
          <p class="truncate font-mono text-2xs text-faint">
            ID {{ run.id.slice(0, 8) }}…
          </p>
          <p class="truncate text-2xs text-muted">
            Documentos consultados: {{ formatDocumentTypes(run.document_types) }}
          </p>
        </div>
        <div class="flex shrink-0 items-center gap-3">
          <UBadge
            :color="syncStore.statusColor(run.status)"
            variant="subtle"
            size="sm"
            class="gap-1.5 font-medium"
          >
            <span
              v-if="isActive(run)"
              class="size-1.5 animate-pulse rounded-full bg-current"
            />
            {{ syncStore.statusLabel(run.status) }}
          </UBadge>
          <span class="font-mono text-xs tabular-nums text-muted">
            {{ run.totals.imported }}/{{ run.totals.found }}
          </span>
        </div>
      </li>
    </ul>

    <div
      v-else
      class="px-4 py-6 text-center text-xs text-muted"
    >
      Aún no hay sincronizaciones registradas.
    </div>
  </section>
</template>
