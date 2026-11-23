<script setup lang="ts">
import { computed, defineAsyncComponent, onMounted, onUnmounted, ref, watch } from 'vue';

const SriSyncRunDetailDrawer = defineAsyncComponent(() => import('@/components/sri/SriSyncRunDetailDrawer.vue'));
SriSyncRunDetailDrawer.name = 'SriSyncRunDetailDrawer';
import SriSyncRunHistory from '@/components/sri/SriSyncRunHistory.vue';
import AppPage from '@/components/layout/AppPage.vue';
import AppShell from '@/layouts/AppShell.vue';
import CompanyRequiredEmptyState from '@/components/companies/CompanyRequiredEmptyState.vue';
import { useRouter } from 'vue-router';

import { documentTypeOptions } from '@/lib/sri';
import { useCompanyStore } from '@/stores/company';
import { useSriCredentialStore } from '@/stores/sriCredential';
import { useSyncStore } from '@/stores/sync';

const router = useRouter();
const companyStore = useCompanyStore();
const syncStore = useSyncStore();
const credentialStore = useSriCredentialStore();

const credentialMissing = ref(false);
const credentialCompanyName = ref('');

/** Período actual en formato `AAAA-MM`, usado como rango por defecto. */
function currentPeriod(): string {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
}

/** Cada cuánto se refresca el historial mientras haya un run en curso. */
const HISTORY_REFRESH_MS = 5000;

const formCompanyId = ref('');
const formFrom = ref(currentPeriod());
const formTo = ref(currentPeriod());
const formForce = ref(false);
const selectedTypes = ref<string[]>(documentTypeOptions.map((type) => type.value));
const starting = ref(false);
const startError = ref<string | null>(null);

const detailOpen = ref(false);

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
    } else {
        detailOpen.value = true;
    }
}

/**
 * Abre el detalle de un run del historial.
 *
 * El `clearSelection` evita que el drawer muestre por un instante el run
 * anterior mientras llega la información del seleccionado.
 */
function goToCredential(): void {
    const company = companyStore.activeCompany ?? companyStore.companies[0] ?? null;

    void router.push({
        name: 'companies',
        query: company ? { credential: company.id } : {},
    });
}

function selectRun(id: string): void {
    syncStore.clearSelection();
    detailOpen.value = true;
    void syncStore.loadRun(id);
}

let historyTimer: ReturnType<typeof setInterval> | null = null;

async function checkCredential(): Promise<void> {
    const company = companyStore.activeCompany ?? companyStore.companies[0] ?? null;

    if (!company) {
        credentialMissing.value = false;
        credentialCompanyName.value = '';

        return;
    }

    credentialCompanyName.value = company.trade_name || company.legal_name;

    await credentialStore.fetchCredential(company.id);
    credentialMissing.value = credentialStore.credential === null;
}

onMounted(() => {
    if (!companyStore.loaded) {
        void companyStore.fetchCompanies().then(() => {
            void checkCredential();
        });
    } else {
        void checkCredential();
    }

    if (companyStore.hasCompany) {
        void syncStore.fetchRuns();
    }

    historyTimer = setInterval(() => {
        if (syncStore.activeRuns.length > 0) {
            void syncStore.fetchRuns();
        }
    }, HISTORY_REFRESH_MS);
});

onUnmounted(() => {
    syncStore.stopPolling();
    if (historyTimer) {
        clearInterval(historyTimer);
        historyTimer = null;
    }
});
</script>

<template>
  <AppShell>
    <AppPage
      title="Monitor de sincronización SRI"
      description="Inicia una descarga del portal SRI y sigue su progreso en vivo."
    >
      <CompanyRequiredEmptyState
        v-if="!companyStore.loading && !companyStore.hasCompany"
        description="Necesitas una empresa activa para sincronizar comprobantes con el SRI."
      />

      <template v-else>
        <!-- Formulario para iniciar sync -->
        <section class="rounded-lg border border-default bg-elevated/50 p-5">
          <h2 class="text-sm font-medium text-highlighted">
            Nueva sincronización
          </h2>

          <UAlert
            v-if="credentialMissing"
            color="warning"
            variant="soft"
            icon="i-lucide-key-round"
            title="Sin credenciales SRI"
            :description="`La empresa ${credentialCompanyName} no tiene credenciales SRI activas.`"
            class="mt-3"
          >
            <template #actions>
              <UButton
                color="warning"
                variant="solid"
                size="sm"
                icon="i-lucide-key-round"
                label="Configurar credencial"
                @click="goToCredential"
              />
            </template>
          </UAlert>

          <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <UFormField
              label="Empresa"
              name="company"
            >
              <USelect
                v-model="formCompanyId"
                :items="companyOptions"
                placeholder="Selecciona una empresa"
                color="neutral"
                variant="outline"
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
                color="neutral"
                variant="outline"
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
                color="neutral"
                variant="outline"
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
                color="neutral"
                variant="outline"
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
              <UTooltip text="Vuelve a descargar e importar los comprobantes aunque ya se hayan registrado. Úsalo si un archivo se importó mal o quedó incompleto; sin esta opción, los comprobantes ya registrados se omiten.">
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
              color="primary"
              variant="solid"
              size="md"
              icon="i-lucide-play"
              label="Iniciar sincronización"
              class="sm:ml-auto"
              :loading="starting"
              :disabled="starting || credentialMissing"
              @click="startSync"
            />
          </div>

          <UAlert
            v-if="startError && !credentialMissing"
            color="error"
            variant="soft"
            icon="i-lucide-alert-circle"
            :description="startError"
            class="mt-3"
          />
        </section>

        <UAlert
          v-if="syncStore.error && !credentialMissing"
          color="error"
          variant="soft"
          icon="i-lucide-alert-circle"
          :description="syncStore.error"
        />

        <SriSyncRunHistory
          :runs="syncStore.runs"
          :loading="syncStore.loading"
          :selected-run-id="syncStore.selectedRunId"
          @select="selectRun"
        />

        <SriSyncRunDetailDrawer v-model:open="detailOpen" />
      </template>
    </AppPage>
  </AppShell>
</template>
