<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';

import { fileErrorLabel, fileStatusLabel, formatDocumentTypes, importOutcomeLabel, syncErrorLabel } from '@/lib/sri';
import { useSyncStore } from '@/stores/sync';

const props = defineProps<{
    open: boolean;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const syncStore = useSyncStore();

const filesExpanded = ref(false);

const now = ref(Date.now());
let ticker: ReturnType<typeof setInterval> | null = null;

function startTicker(): void {
    stopTicker();
    ticker = setInterval(() => {
        now.value = Date.now();
    }, 1000);
}

function stopTicker(): void {
    if (ticker) {
        clearInterval(ticker);
        ticker = null;
    }
}

onBeforeUnmount(stopTicker);

const isOpen = computed({
    get: () => props.open,
    set: (value: boolean) => emit('update:open', value),
});

const run = computed(() => syncStore.selectedRun);

const isActive = computed(() => run.value !== null && syncStore.activeStatuses.includes(run.value.status));

const elapsedTitle = computed(() =>
    isActive.value ? 'Tiempo transcurrido' : 'Tiempo de ejecución del robot',
);

const elapsedLabel = computed(() => {
    const current = run.value;
    if (!current) {
        return null;
    }

    const startRaw = current.started_at ?? current.created_at;
    if (!startRaw) {
        return null;
    }

    const startMs = new Date(startRaw).getTime();
    if (Number.isNaN(startMs)) {
        return null;
    }

    const endRaw = !current.is_active ? (current.finished_at ?? null) : null;
    const endMs = endRaw ? new Date(endRaw).getTime() : now.value;
    if (Number.isNaN(endMs)) {
        return null;
    }

    const seconds = Math.max(0, Math.floor((endMs - startMs) / 1000));
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;

    if (hours > 0) {
        return `${hours}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
    }

    return `${minutes}:${String(secs).padStart(2, '0')}`;
});

const periods = computed(() => run.value?.periods?.join(', ') ?? '—');

const documentTypes = computed(() => formatDocumentTypes(run.value?.document_types));

const failedFiles = computed(() => syncStore.selectedFiles.filter((file) => file.status === 'invalid' || file.error_code));
const skippedFiles = computed(() => syncStore.selectedFiles.filter((file) => file.import_outcome === 'skipped'));

/**
 * Porcentaje mostrado por etapa del pipeline.
 *
 * Un archivo descubierto no es un archivo descargado: `missing` e `invalid` no
 * cuentan como avance, solo `available` y `processed`. Así el progreso no se
 * acerca al 100 % mientras los XML todavía no están en S3. Mientras el run
 * sigue activo nunca llega a 100; solo lo hace al quedar `completed`/`partial`.
 */
const progressPercent = computed(() => {
    const current = run.value;

    if (!current) {
        return 0;
    }

    if (current.status === 'completed' || current.status === 'partial') {
        return 100;
    }

    if (!current.is_active) {
        return current.progress === null
            ? 0
            : Math.round(Math.min(100, Math.max(0, current.progress)));
    }

    const files = syncStore.selectedFiles;

    switch (current.status) {
        case 'invoking':
            return 8;
        case 'downloading': {
            if (files.length === 0) {
                return 20;
            }

            const available = files.filter((file) => ['available', 'processed'].includes(file.status)).length;

            return Math.min(44, Math.round(20 + (available / files.length) * 25));
        }
        case 'parsing': {
            if (current.progress === null) {
                return 45;
            }

            const imported = Math.min(100, Math.max(0, current.progress));

            return Math.min(99, Math.round(45 + (imported / 100) * 54));
        }
        default:
            return 0;
    }
});

// Al cerrar se descarta el run cargado: al reabrir siempre se ve el loader
// hasta que llegue la información del run seleccionado.
watch(isOpen, (open) => {
    if (open) {
        now.value = Date.now();
        startTicker();
    } else {
        stopTicker();
        filesExpanded.value = false;
        syncStore.clearSelection();
    }
}, { immediate: true });

watch(run, (current) => {
    if (current && !current.is_active) {
        stopTicker();
    } else if (isOpen.value) {
        startTicker();
    }
});
</script>

<template>
  <USlideover
    v-model:open="isOpen"
    side="right"
    :ui="{ content: 'max-w-xl z-30', header: 'border-b-0', body: 'pt-2' }"
    title="Detalle de la sincronización"
  >
    <template #body>
      <div
        v-if="syncStore.selectedLoading"
        class="flex flex-col items-center justify-center gap-2 py-16 text-xs text-muted"
      >
        <UIcon
          name="i-lucide-loader-2"
          class="size-5 animate-spin text-muted"
        />
        Cargando detalle de la sincronización…
      </div>

      <div
        v-else-if="run"
        class="space-y-4 text-sm"
      >
        <div>
          <p class="font-mono text-sm text-ink">
            ID {{ run.id }}
          </p>
          <div class="mt-1 flex items-center gap-2">
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
              <UIcon
                v-if="isActive"
                name="i-lucide-loader-2"
                class="size-3 animate-spin"
              />
              {{ syncStore.statusLabel(run.status) }}
            </UBadge>
            <span
              v-if="syncStore.polling"
              class="text-2xs text-faint"
            >
              (actualizando…)
            </span>
          </div>
        </div>

        <dl class="space-y-3">
          <div>
            <dt class="text-2xs uppercase tracking-wider text-faint">
              Período consultado
            </dt>
            <dd class="font-mono text-ink">
              {{ periods }}
            </dd>
          </div>
          <div>
            <dt class="text-2xs uppercase tracking-wider text-faint">
              Rango de fechas
            </dt>
            <dd class="font-mono text-ink">
              {{ run.date_from }} → {{ run.date_to }}
            </dd>
          </div>
          <div>
            <dt class="text-2xs uppercase tracking-wider text-faint">
              Documentos consultados
            </dt>
            <dd class="text-ink">
              {{ documentTypes }}
            </dd>
          </div>
          <div v-if="elapsedLabel">
            <dt class="text-2xs uppercase tracking-wider text-faint">
              {{ elapsedTitle }}
            </dt>
            <dd class="font-mono text-ink">
              {{ elapsedLabel }}
            </dd>
          </div>
        </dl>

        <div>
          <div class="flex items-center justify-between">
            <p class="text-2xs text-faint">
              Progreso
            </p>
            <p class="font-mono text-sm font-semibold tabular-nums text-ink">
              {{ progressPercent }}%
            </p>
          </div>
          <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-elevated">
            <div
              class="h-full bg-accent transition-all duration-500"
              :style="{ width: progressPercent + '%' }"
            />
          </div>
        </div>

        <!-- El error del run es un veredicto final: mientras siga activo, el
             parser puede dejar avisos transitorios (p. ej. `incomplete_package`)
             que la importación todavía va a resolver. -->
        <UAlert
          v-if="run.error_message && !isActive"
          color="error"
          variant="soft"
          icon="i-lucide-alert-circle"
          :title="syncErrorLabel(run.error_code)"
          :description="run.error_message"
        />

        <div class="grid grid-cols-2 gap-3">
          <div class="rounded-md border border-line bg-canvas p-3">
            <p class="text-2xs uppercase tracking-wider text-faint">
              Encontrados
            </p>
            <p class="font-mono text-lg font-semibold tabular-nums text-ink">
              {{ run.totals.found }}
            </p>
          </div>
          <div class="rounded-md border border-line bg-canvas p-3">
            <p class="text-2xs uppercase tracking-wider text-faint">
              Importados
            </p>
            <p class="font-mono text-lg font-semibold tabular-nums text-ink">
              {{ run.totals.imported }}
            </p>
          </div>
          <div class="rounded-md border border-line bg-canvas p-3">
            <p class="text-2xs uppercase tracking-wider text-faint">
              Omitidos
            </p>
            <p class="font-mono text-lg font-semibold tabular-nums text-ink">
              {{ run.totals.skipped }}
            </p>
            <p
              v-if="skippedFiles.length > 0"
              class="mt-1 text-2xs text-faint"
            >
              {{ skippedFiles.length }} archivo(s) ya registrados
            </p>
          </div>
          <div class="rounded-md border border-line bg-canvas p-3">
            <p class="text-2xs uppercase tracking-wider text-faint">
              Fallidos
            </p>
            <p class="font-mono text-lg font-semibold tabular-nums text-ink">
              {{ run.totals.failed }}
            </p>
            <p
              v-if="failedFiles.length > 0"
              class="mt-1 text-2xs text-danger-ink"
            >
              {{ failedFiles.length }} con error
            </p>
          </div>
        </div>

        <div v-if="syncStore.selectedFiles.length > 0">
          <button
            type="button"
            class="flex w-full items-center justify-between rounded-md border border-line bg-canvas px-3 py-2 text-xs font-medium text-ink transition hover:bg-elevated"
            @click="filesExpanded = !filesExpanded"
          >
            <span>
              Archivos ({{ syncStore.selectedFiles.length }})
            </span>
            <UIcon
              :name="filesExpanded ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
              class="size-4 text-muted"
            />
          </button>
          <p class="mt-1 text-2xs text-faint">
            Estados: En S3 = XML en el bucket, aún no importado · Procesado = importado · No encontrado = no estaba en S3 · Inválido = XML malformado.
          </p>
          <ul
            v-if="filesExpanded"
            class="mt-2 divide-y divide-line rounded-md border border-line"
          >
            <li
              v-for="file in syncStore.selectedFiles"
              :key="file.id"
              class="flex items-center justify-between gap-3 px-3 py-2 text-xs"
            >
              <div class="min-w-0">
                <p class="truncate font-mono text-ink">
                  {{ file.file_name }}
                </p>
                <p
                  v-if="file.error_message"
                  class="truncate text-2xs text-ink"
                >
                  {{ fileErrorLabel(file.error_code) }} · {{ file.error_message }}
                </p>
              </div>
              <div class="flex items-center gap-2">
                <UBadge
                  :color="file.status === 'processed' ? 'success' : file.status === 'invalid' ? 'error' : 'neutral'"
                  variant="subtle"
                  size="xs"
                >
                  {{ fileStatusLabel(file.status) }}
                </UBadge>
                <span
                  v-if="importOutcomeLabel(file.import_outcome)"
                  class="text-2xs text-faint"
                >
                  {{ importOutcomeLabel(file.import_outcome) }}
                </span>
              </div>
            </li>
          </ul>
        </div>
      </div>

      <div
        v-else
        class="text-xs text-muted"
      >
        No se pudo cargar el detalle de la sincronización.
      </div>
    </template>
  </USlideover>
</template>
