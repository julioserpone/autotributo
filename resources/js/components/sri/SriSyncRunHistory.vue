<script setup lang="ts">
import SriSyncRunStatus from '@/components/sri/SriSyncRunStatus.vue';
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
  <section class="rounded-lg border border-default bg-elevated/50">
    <div class="border-b border-default px-4 py-3">
      <h2 class="text-sm font-medium text-highlighted">
        Historial de sincronizaciones
      </h2>
    </div>

    <!-- El historial solo reemplaza la lista por «Cargando…» en la primera
         carga: durante el refresco automático la lista se mantiene visible. -->
    <div
      v-if="loading && runs.length === 0"
      class="px-4 py-6 text-center text-xs text-muted"
    >
      Cargando…
    </div>

    <ul
      v-else-if="runs.length > 0"
      class="divide-y divide-default"
    >
      <li
        v-for="run in runs"
        :key="run.id"
        class="cursor-pointer px-4 py-3 transition hover:bg-elevated/60"
        :class="{ 'bg-elevated/40': run.id === selectedRunId }"
        @click="emit('select', run.id)"
      >
        <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between sm:gap-3">
          <div class="min-w-0 flex-1">
            <!-- Desktop: «ID … | Periodo …» en una sola línea. Mobile: el
                 período baja a su propia línea y el ID se mantiene completo. -->
            <div class="flex items-start gap-2">
              <UIcon
                v-if="isActive(run)"
                name="i-lucide-loader-2"
                class="mt-0.5 size-3.5 shrink-0 animate-spin text-info-ink"
              />
              <p class="min-w-0 font-mono text-xs text-highlighted">
                <span class="break-all">ID {{ run.id }}</span>
                <span class="hidden text-dimmed sm:inline"> | </span>
                <span class="mt-0.5 block text-xs text-muted sm:mt-0 sm:inline sm:text-dimmed">Periodo {{ formatPeriods(run) }}</span>
              </p>
            </div>
            <div class="mt-1 flex items-center justify-between gap-3">
              <p class="min-w-0 truncate text-xs text-muted">
                Documentos consultados: {{ formatDocumentTypes(run.document_types) }}
              </p>
              <SriSyncRunStatus
                :run="run"
                class="flex shrink-0 items-center gap-3 sm:hidden"
              />
            </div>
          </div>
          <SriSyncRunStatus
            :run="run"
            class="hidden shrink-0 items-center gap-3 sm:flex"
          />
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
