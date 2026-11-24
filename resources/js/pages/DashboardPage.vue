<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';

import AppShell from '@/layouts/AppShell.vue';
import AppPage from '@/components/layout/AppPage.vue';
import CompanyRequiredEmptyState from '@/components/companies/CompanyRequiredEmptyState.vue';
import DashboardKpiCards from '@/components/dashboard/DashboardKpiCards.vue';
import DashboardIvaComparison from '@/components/dashboard/DashboardIvaComparison.vue';
import DashboardTopProviders from '@/components/dashboard/DashboardTopProviders.vue';
import DashboardAlertsPanel from '@/components/dashboard/DashboardAlertsPanel.vue';
import DashboardPeriodPicker from '@/components/dashboard/DashboardPeriodPicker.vue';
import DashboardSyncModal from '@/components/dashboard/DashboardSyncModal.vue';
import { useCompanyStore } from '@/stores/company';
import { useDashboardStore } from '@/stores/dashboard';
import { formatCents, periodLabel, periodStatusColor, periodStatusLabel } from '@/lib/dashboard';

const route = useRoute();
const companyStore = useCompanyStore();
const store = useDashboardStore();

const syncOpen = ref(false);

/**
 * `?preview=1` se conserva para demos de diseño, pero ya no es el estado por
 * defecto (F4-08): el panel nace de `period_aggregates` y `fiscal_findings`.
 */
const isPreview = computed(() => route.query.preview === '1');

const dashboard = computed(() => store.dashboard);
const periodTitle = computed(() => periodLabel(store.year, store.month));
const statusLabel = computed(() => periodStatusLabel(dashboard.value?.period.status ?? null));
const statusColor = computed(() => periodStatusColor(dashboard.value?.period.status ?? null));

/** Con datos vivos nunca se enseña un panorama vacío sin contexto. */
const showLiveData = computed(() => dashboard.value !== null && !isPreview.value);

/**
 * Primera resolución de empresas terminada: evita parpadear el estado vacío o
 * los ceros mientras `fetchCompanies` sigue en vuelo.
 */
const emptyStateReady = computed(
    () => companyStore.loaded && !companyStore.loading && !companyStore.hasCompany,
);

function money(cents: number): string {
    return formatCents(cents);
}

async function refresh(): Promise<void> {
    const companyId = companyStore.activeCompany?.id;

    if (companyId === undefined || companyId === null) {
        store.reset();

        return;
    }

    await store.fetchDashboard(companyId);
}

function changePeriod(payload: { year: number; month: number }): void {
    store.setPeriod(payload.year, payload.month);
}

watch(
    () => [companyStore.activeCompany?.id, store.year, store.month],
    () => {
        void refresh();
    },
);

onMounted(async () => {
    if (!companyStore.loaded) {
        await companyStore.fetchCompanies();
    }

    await refresh();
});
</script>

<template>
  <AppShell>
    <AppPage
      title="Panel Tributario"
      :description="`${periodTitle} · IVA de ventas y compras, retenciones, proveedores y alertas del período.`"
    >
      <template #actions>
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-refresh-cw"
          label="Actualizar"
          class="h-8"
          :loading="store.loading"
          :disabled="!companyStore.hasCompany"
          @click="refresh"
        />
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-radar"
          label="Sincronizar SRI"
          class="h-8"
          :disabled="!companyStore.hasCompany"
          @click="syncOpen = true"
        />
        <UButton
          color="primary"
          variant="solid"
          size="sm"
          icon="i-lucide-file-search"
          label="Ver bandeja"
          class="h-8"
          :disabled="!companyStore.hasCompany"
          :to="{ name: 'fiscal_documents' }"
        />
      </template>

      <CompanyRequiredEmptyState
        v-if="emptyStateReady"
        description="Sin empresas no hay comprobantes que sincronizar ni clasificar."
      />

      <template v-else>
        <UAlert
          v-if="store.error && !isPreview"
          color="error"
          variant="soft"
          icon="i-lucide-alert-circle"
          title="No se pudo cargar el panel"
          :description="store.error"
        >
          <template #actions>
            <UButton
              color="neutral"
              variant="outline"
              size="xs"
              label="Reintentar"
              @click="refresh"
            />
          </template>
        </UAlert>

        <div
          v-if="isPreview"
          class="flex items-center gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-2.5 text-xs text-amber-700 dark:text-amber-300"
        >
          <UIcon
            name="i-lucide-eye"
            class="size-4 shrink-0"
          />
          <span>
            Vista previa de interfaz. Las cifras se muestran en cero porque la vista previa con datos de ejemplo se retiró en F4-08.
          </span>
          <RouterLink
            to="/"
            class="ml-auto font-medium underline-offset-4 hover:underline"
          >
            Salir de la vista previa
          </RouterLink>
        </div>

        <article
          v-if="showLiveData && dashboard"
          class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-default bg-elevated/50 px-4 py-3"
        >
          <div class="flex items-center gap-3">
            <UIcon
              name="i-lucide-building-2"
              class="size-5 text-muted"
            />
            <div>
              <p class="text-sm font-medium text-highlighted">
                {{ dashboard.company.legal_name }}
              </p>
              <p class="font-mono text-2xs text-muted">
                RUC {{ dashboard.company.ruc }}
              </p>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-3 text-xs text-muted">
            <DashboardPeriodPicker
              :year="dashboard.period.year"
              :month="dashboard.period.month"
              :loading="store.loading"
              @change-period="changePeriod"
            />
            <UBadge
              :color="statusColor"
              variant="subtle"
              size="md"
              class="gap-1.5 font-medium"
            >
              <span class="size-1.5 rounded-full bg-current" />
              {{ statusLabel }}
            </UBadge>
          </div>
        </article>

        <p
          v-if="showLiveData"
          class="text-xs text-muted"
        >
          <template v-if="dashboard?.period.computed_at">
            Agregados calculados el {{ new Date(dashboard.period.computed_at).toLocaleString('es-EC') }}.
            Se recalculan automáticamente al cerrar cada sincronización con el SRI y al importar XML.
          </template>
          <template v-else>
            Este período todavía no tiene comprobantes procesados, por eso las cifras están en cero. Los
            agregados se calculan automáticamente al cerrar una sincronización con el SRI o al importar un
            XML autorizado.
          </template>
        </p>

        <DashboardKpiCards
          v-if="dashboard"
          :dashboard="dashboard"
          :loading="store.loading"
        />

        <UAlert
          v-if="showLiveData && !store.loading && !store.hasActivity"
          color="info"
          variant="soft"
          icon="i-lucide-info"
          title="Sin comprobantes en el período"
          description="Sincroniza con el SRI o carga un XML autorizado; los agregados se calculan al cerrar la sincronización."
        />

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
          <DashboardIvaComparison
            v-if="dashboard && showLiveData"
            :sales="dashboard.iva.sales"
            :purchases="dashboard.iva.purchases"
            :net-cents="dashboard.iva.net_cents"
          />

          <DashboardTopProviders
            v-if="dashboard"
            :providers="dashboard.top_providers"
            :loading="store.loading"
          />
        </div>

        <DashboardAlertsPanel
          v-if="dashboard"
          :findings="dashboard.findings"
          :caps="dashboard.personal_expense_caps"
          :year="dashboard.period.year"
          :loading="store.loading"
        />

        <section
          v-if="showLiveData && dashboard"
          class="grid grid-cols-1 gap-4 sm:grid-cols-2"
        >
          <article class="rounded-lg border border-default bg-elevated/50 p-5">
            <h2 class="text-sm font-medium text-highlighted">
              Retenciones practicadas
            </h2>
            <p class="mt-0.5 text-xs text-muted">
              Retenciones de renta e IVA emitidas por la empresa en el período.
            </p>
            <div class="mt-4 flex items-baseline justify-between">
              <span class="font-mono text-lg font-semibold tabular-nums text-highlighted">
                {{ money(dashboard.withholdings.practiced_cents) }}
              </span>
              <span class="font-mono text-2xs text-muted">
                {{ dashboard.withholdings.practiced_documents }} docs
              </span>
            </div>
          </article>
          <article class="rounded-lg border border-default bg-elevated/50 p-5">
            <h2 class="text-sm font-medium text-highlighted">
              Retenciones recibidas
            </h2>
            <p class="mt-0.5 text-xs text-muted">
              Retenciones que terceros practicaron a la empresa en el período.
            </p>
            <div class="mt-4 flex items-baseline justify-between">
              <span class="font-mono text-lg font-semibold tabular-nums text-highlighted">
                {{ money(dashboard.withholdings.received_cents) }}
              </span>
              <span class="font-mono text-2xs text-muted">
                {{ dashboard.withholdings.received_documents }} docs
              </span>
            </div>
          </article>
        </section>
      </template>

      <DashboardSyncModal
        v-model:open="syncOpen"
        :year="store.year"
        :month="store.month"
        :requires-two-factor-setup="companyStore.requiresTwoFactorSetup"
      />
    </AppPage>
  </AppShell>
</template>
