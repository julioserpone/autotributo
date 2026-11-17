<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

import AppShell from '@/layouts/AppShell.vue';
import { useCompanyStore } from '@/stores/company';
import { useSyncStore } from '@/stores/sync';

const companyStore = useCompanyStore();
const syncStore = useSyncStore();

const documentTypeOptions = [
    { label: 'Facturas', value: '01' },
    { label: 'Liquidaciones de compra', value: '03' },
    { label: 'Notas de crédito', value: '04' },
    { label: 'Notas de débito', value: '05' },
    { label: 'Comprobantes de retención', value: '07' },
];

/** Período actual en formato `AAAA-MM`, usado como rango por defecto. */
function currentPeriod(): string {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
}

const formCompanyId = ref('');
const formFrom = ref(currentPeriod());
const formTo = ref(currentPeriod());
const formForce = ref(false);
const selectedTypes = ref<string[]>(documentTypeOptions.map((type) => type.value));
const starting = ref(false);
const startError = ref<string | null>(null);

const companyOptions = computed(() => companyStore.companies.map((company) => ({
    label: company.trade_name || company.legal_name,
    value: company.id,
})));

const documentTypesSummary = computed(() => {
    if (selectedTypes.value.length === 0) {
        return 'Ningún tipo seleccionado';
    }

    if (selectedTypes.value.length === documentTypeOptions.length) {
        return 'Todos los tipos';
    }

    if (selectedTypes.value.length === 1) {
        const selected = documentTypeOptions.find((type) => type.value === selectedTypes.value[0]);

        return selected?.label ?? '1 tipo seleccionado';
    }

    return `${selectedTypes.value.length} tipos seleccionados`;
});

// Preselecciona la empresa activa (o la primera) en cuanto el listado llega.
watch(companyOptions, (options) => {
    if (formCompanyId.value !== '' || options.length === 0) {
        return;
    }

    const preferred = companyStore.activeId !== null && options.some((option) => option.value === companyStore.activeId)
        ? companyStore.activeId
        : options[0]?.value;

    if (preferred) {
        formCompanyId.value = preferred;
    }
}, { immediate: true });

const progressPercent = computed(() => {
    const run = syncStore.selectedRun;
    if (!run) return 0;
    return run.progress ?? 0;
});

async function startSync(): Promise<void> {
    if (starting.value) return;
    if (!formCompanyId.value) {
        startError.value = 'Selecciona una empresa.';
        return;
    }

    if (selectedTypes.value.length === 0) {
        startError.value = 'Selecciona al menos un tipo de documento.';
        return;
    }

    const periods = syncStore.monthsBetween(formFrom.value, formTo.value);
    if (periods.length === 0) {
        startError.value = 'Indica un rango de períodos válido (AAAA-MM).';
        return;
    }

    starting.value = true;
    startError.value = null;

    const company = companyStore.companies.find((item) => item.id === formCompanyId.value);

    const id = await syncStore.startSync({
        companyId: company?.id ?? formCompanyId.value,
        periods,
        documentTypes: selectedTypes.value,
        force: formForce.value,
    });

    starting.value = false;

    if (!id) {
        startError.value = syncStore.error ?? 'No se pudo iniciar la sincronización.';
    }
}

function selectRun(id: string): void {
    void syncStore.loadRun(id);
}

onMounted(() => {
    if (!companyStore.loaded) {
        void companyStore.fetchCompanies();
    }
    void syncStore.fetchRuns();
});

onUnmounted(() => {
    syncStore.stopPolling();
});
</script>

<template>
  <AppShell title="Sincronización">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h1 class="text-lg font-semibold tracking-tight text-ink">
          Monitor de sincronización SRI
        </h1>
        <p class="mt-1 text-xs text-muted">
          Inicia una descarga del portal SRI y sigue su progreso en vivo.
        </p>
      </div>
    </header>

    <!-- Formulario para iniciar sync -->
    <section class="rounded-lg border border-line bg-surface p-5 shadow-2xs dark:shadow-none">
      <h2 class="text-sm font-medium text-ink">
        Nueva sincronización
      </h2>

      <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <UFormField
          label="Empresa"
          name="company"
        >
          <USelect
            v-model="formCompanyId"
            :items="companyOptions"
            placeholder="Selecciona una empresa"
            size="md"
            class="w-full"
          />
        </UFormField>

        <UFormField
          label="Tipos de documento"
          name="document_types"
        >
          <USelectMenu
            v-model="selectedTypes"
            :items="documentTypeOptions"
            value-key="value"
            multiple
            :search-input="false"
            size="md"
            class="w-full"
          >
            <span class="truncate">{{ documentTypesSummary }}</span>
          </USelectMenu>
        </UFormField>

        <UFormField
          label="Desde (AAAA-MM)"
          name="from"
        >
          <UInput
            v-model="formFrom"
            placeholder="2026-09"
            size="md"
            class="w-full"
          />
        </UFormField>

        <UFormField
          label="Hasta (AAAA-MM)"
          name="to"
        >
          <UInput
            v-model="formTo"
            placeholder="2026-11"
            size="md"
            class="w-full"
          />
        </UFormField>
      </div>

      <div class="mt-4 flex flex-wrap items-center gap-4">
        <div class="flex items-center gap-1.5">
          <UCheckbox
            v-model="formForce"
            label="Forzar re-descarga"
          />
          <UTooltip text="Vuelve a descargar los comprobantes aunque ya se hayan importado. Úsalo si un archivo se importó mal o quedó incompleto; sin esta opción, los comprobantes ya registrados se omiten.">
            <UButton
              color="neutral"
              variant="ghost"
              size="xs"
              icon="i-lucide-info"
              square
              aria-label="Qué significa forzar re-descarga"
            />
          </UTooltip>
        </div>

        <UButton
          color="neutral"
          variant="solid"
          size="md"
          icon="i-lucide-play"
          label="Iniciar sincronización"
          class="sm:ml-auto"
          :loading="starting"
          :disabled="starting"
          @click="startSync"
        />
      </div>

      <UAlert
        v-if="startError"
        color="error"
        variant="soft"
        icon="i-lucide-alert-circle"
        :description="startError"
        class="mt-3"
      />
    </section>

    <UAlert
      v-if="syncStore.error"
      color="error"
      variant="soft"
      icon="i-lucide-alert-circle"
      :description="syncStore.error"
    />

    <!-- Detalle del run seleccionado -->
    <section
      v-if="syncStore.selectedRun"
      class="rounded-lg border border-line bg-surface p-5 shadow-2xs dark:shadow-none"
    >
      <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div class="flex items-center gap-2">
            <h2 class="text-sm font-medium text-ink">
              Run {{ syncStore.selectedRun.id.slice(0, 8) }}…
            </h2>
            <UBadge
              :color="syncStore.statusColor(syncStore.selectedRun.status)"
              variant="subtle"
              size="xs"
              class="gap-1.5 font-medium"
            >
              {{ syncStore.statusLabel(syncStore.selectedRun.status) }}
            </UBadge>
            <span
              v-if="syncStore.polling"
              class="text-2xs text-faint"
            >
              (actualizando…)
            </span>
          </div>
          <p class="mt-1 text-xs text-muted">
            {{ syncStore.selectedRun.date_from }} → {{ syncStore.selectedRun.date_to }}
            · {{ syncStore.selectedRun.periods?.join(', ') }}
          </p>
        </div>

        <div class="flex items-center gap-3">
          <div class="text-right">
            <p class="text-2xs text-faint">
              Progreso
            </p>
            <p class="font-mono text-sm font-semibold tabular-nums text-ink">
              {{ progressPercent }}%
            </p>
          </div>
          <div class="h-2 w-40 overflow-hidden rounded-full bg-elevated">
            <div
              class="h-full bg-accent transition-all"
              :style="{ width: progressPercent + '%' }"
            />
          </div>
        </div>
      </div>

      <UAlert
        v-if="syncStore.selectedRun.error_message"
        color="error"
        variant="soft"
        icon="i-lucide-alert-circle"
        :title="syncStore.selectedRun.error_code ?? 'Error'"
        :description="syncStore.selectedRun.error_message"
        class="mt-3"
      />

      <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="rounded-md border border-line bg-canvas p-3">
          <p class="text-2xs uppercase tracking-wider text-faint">
            Encontrados
          </p>
          <p class="font-mono text-lg font-semibold tabular-nums text-ink">
            {{ syncStore.selectedRun.totals.found }}
          </p>
        </div>
        <div class="rounded-md border border-line bg-canvas p-3">
          <p class="text-2xs uppercase tracking-wider text-faint">
            Importados
          </p>
          <p class="font-mono text-lg font-semibold tabular-nums text-ink">
            {{ syncStore.selectedRun.totals.imported }}
          </p>
        </div>
        <div class="rounded-md border border-line bg-canvas p-3">
          <p class="text-2xs uppercase tracking-wider text-faint">
            Omitidos
          </p>
          <p class="font-mono text-lg font-semibold tabular-nums text-ink">
            {{ syncStore.selectedRun.totals.skipped }}
          </p>
        </div>
        <div class="rounded-md border border-line bg-canvas p-3">
          <p class="text-2xs uppercase tracking-wider text-faint">
            Fallidos
          </p>
          <p class="font-mono text-lg font-semibold tabular-nums text-ink">
            {{ syncStore.selectedRun.totals.failed }}
          </p>
        </div>
      </div>

      <div
        v-if="syncStore.selectedFiles.length > 0"
        class="mt-4"
      >
        <h3 class="text-xs font-medium text-ink">
          Archivos
        </h3>
        <ul class="mt-2 divide-y divide-line rounded-md border border-line">
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
                class="truncate text-2xs text-danger-ink"
              >
                {{ file.error_code }} · {{ file.error_message }}
              </p>
            </div>
            <div class="flex items-center gap-2">
              <UBadge
                :color="file.status === 'completed' ? 'success' : file.status === 'failed' ? 'error' : 'neutral'"
                variant="subtle"
                size="xs"
              >
                {{ file.status }}
              </UBadge>
              <span
                v-if="file.import_outcome"
                class="text-2xs text-faint"
              >
                {{ file.import_outcome }}
              </span>
            </div>
          </li>
        </ul>
      </div>
    </section>

    <!-- Historial -->
    <section class="rounded-lg border border-line bg-surface shadow-2xs dark:shadow-none">
      <div class="border-b border-line px-4 py-3">
        <h2 class="text-sm font-medium text-ink">
          Historial de sincronizaciones
        </h2>
      </div>

      <div
        v-if="syncStore.loading"
        class="px-4 py-6 text-center text-xs text-muted"
      >
        Cargando…
      </div>

      <ul
        v-else-if="syncStore.runs.length > 0"
        class="divide-y divide-line"
      >
        <li
          v-for="run in syncStore.runs"
          :key="run.id"
          class="flex cursor-pointer items-center justify-between gap-3 px-4 py-3 transition hover:bg-elevated/60"
          :class="{ 'bg-elevated/40': run.id === syncStore.selectedRunId }"
          @click="selectRun(run.id)"
        >
          <div class="min-w-0">
            <p class="font-mono text-xs text-ink">
              {{ run.id.slice(0, 8) }}…
            </p>
            <p class="text-2xs text-faint">
              {{ run.created_at ? new Date(run.created_at).toLocaleString('es-EC') : '—' }}
            </p>
          </div>
          <div class="flex items-center gap-3">
            <UBadge
              :color="syncStore.statusColor(run.status)"
              variant="subtle"
              size="xs"
              class="gap-1.5 font-medium"
            >
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
  </AppShell>
</template>
