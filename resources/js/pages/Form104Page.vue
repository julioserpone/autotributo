<script setup lang="ts">
import { computed, onMounted, watch } from 'vue';

import AppShell from '@/layouts/AppShell.vue';
import AppPage from '@/components/layout/AppPage.vue';
import CompanyRequiredEmptyState from '@/components/companies/CompanyRequiredEmptyState.vue';
import DashboardPeriodPicker from '@/components/dashboard/DashboardPeriodPicker.vue';
import Form104SectionTable from '@/components/form104/Form104SectionTable.vue';
import { useCompanyStore } from '@/stores/company';
import { useForm104Store } from '@/stores/form104';
import { formatCents, periodLabel, periodStatusColor, periodStatusLabel } from '@/lib/dashboard';
import { form104Kpis, groupForm104Lines } from '@/lib/form104';

/**
 * Vista del Formulario 104 (F4-09).
 *
 * Muestra el formulario oficial completo —todos sus casilleros, en el orden
 * del XLS del SRI— con las cifras derivadas de los comprobantes del período,
 * el estado de cada campo y el drill-down a la bandeja. Es un **borrador**:
 * la declaración persistida, el arrastre de crédito y la exportación son de
 * F5-02/F5-05 y requieren la firma del contador.
 */
const companyStore = useCompanyStore();
const store = useForm104Store();

const emptyStateReady = computed(
    () => companyStore.loaded && !companyStore.loading && !companyStore.hasCompany,
);

const draft = computed(() => store.draft);
const periodTitle = computed(() => periodLabel(store.year, store.month));
const statusLabel = computed(() => periodStatusLabel(draft.value?.period.status ?? null));
const statusColor = computed(() => periodStatusColor(draft.value?.period.status ?? null));
const kpis = computed(() => (draft.value === null ? null : form104Kpis(draft.value)));
const groups = computed(() => groupForm104Lines(store.lines, store.sections));

async function refresh(): Promise<void> {
    const companyId = companyStore.activeCompany?.id;

    if (companyId === undefined || companyId === null) {
        store.reset();

        return;
    }

    await store.fetchDraft(companyId);
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
      title="Formulario 104"
      :description="`${periodTitle} · IVA del período con los casilleros del formulario oficial del SRI.`"
    >
      <template #actions>
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-refresh-cw"
          label="Recalcular"
          class="h-8"
          :loading="store.loading"
          :disabled="!companyStore.hasCompany"
          @click="refresh"
        />
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-shield-alert"
          label="Hallazgos"
          class="h-8"
          :disabled="!companyStore.hasCompany"
          :to="{ name: 'fiscal-findings' }"
        />
      </template>

      <CompanyRequiredEmptyState
        v-if="emptyStateReady"
        description="Sin empresas no hay comprobantes que consolidar en el 104."
      />

      <template v-else>
        <UAlert
          v-if="store.error"
          color="error"
          variant="soft"
          icon="i-lucide-alert-circle"
          title="No se pudo cargar el formulario"
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

        <UAlert
          v-if="draft"
          color="warning"
          variant="soft"
          icon="i-lucide-file-pen-line"
          title="Borrador, no una declaración"
          :description="draft.status.notice"
        />

        <article
          v-if="draft"
          class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-default bg-elevated/50 px-4 py-3"
        >
          <div class="flex items-center gap-3">
            <UIcon
              name="i-lucide-building-2"
              class="size-5 text-muted"
            />
            <div>
              <p class="text-sm font-medium text-highlighted">
                {{ draft.company.legal_name }}
              </p>
              <p class="font-mono text-2xs text-muted">
                RUC {{ draft.company.ruc }} · Formulario {{ draft.period.form_code }} ({{ draft.period.resolution }})
              </p>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-3 text-xs text-muted">
            <DashboardPeriodPicker
              :year="draft.period.year"
              :month="draft.period.month"
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
          v-if="draft?.period.computed_at"
          class="text-xs text-muted"
        >
          Agregados calculados el {{ new Date(draft.period.computed_at).toLocaleString('es-EC') }}.
        </p>

        <div
          v-if="kpis"
          class="grid grid-cols-2 gap-3 lg:grid-cols-5"
        >
          <article class="rounded-lg border border-default bg-elevated/50 p-4">
            <p class="text-2xs uppercase tracking-wider text-dimmed">
              Ventas gravadas
            </p>
            <p class="mt-1 font-mono text-base font-semibold tabular-nums text-highlighted">
              {{ formatCents(kpis.gravadasBaseCents) }}
            </p>
            <p class="mt-0.5 text-2xs text-muted">
              Campo 401
            </p>
          </article>
          <article class="rounded-lg border border-default bg-elevated/50 p-4">
            <p class="text-2xs uppercase tracking-wider text-dimmed">
              IVA ventas
            </p>
            <p class="mt-1 font-mono text-base font-semibold tabular-nums text-highlighted">
              {{ formatCents(kpis.ivaVentasCents) }}
            </p>
            <p class="mt-0.5 text-2xs text-muted">
              Campo 429
            </p>
          </article>
          <article class="rounded-lg border border-default bg-elevated/50 p-4">
            <p class="text-2xs uppercase tracking-wider text-dimmed">
              IVA compras
            </p>
            <p class="mt-1 font-mono text-base font-semibold tabular-nums text-highlighted">
              {{ formatCents(kpis.ivaComprasCents) }}
            </p>
            <p class="mt-0.5 text-2xs text-muted">
              Campo 529
            </p>
          </article>
          <article class="rounded-lg border border-default bg-elevated/50 p-4">
            <p class="text-2xs uppercase tracking-wider text-dimmed">
              Crédito tributario
            </p>
            <p class="mt-1 font-mono text-base font-semibold tabular-nums text-highlighted">
              {{ formatCents(kpis.creditoCents) }}
            </p>
            <p class="mt-0.5 text-2xs text-muted">
              Campo 564
            </p>
          </article>
          <article class="rounded-lg border border-default bg-elevated/50 p-4">
            <p class="text-2xs uppercase tracking-wider text-dimmed">
              Retenciones IVA
            </p>
            <p class="mt-1 font-mono text-base font-semibold tabular-nums text-highlighted">
              {{ formatCents(kpis.retencionesIvaCents) }}
            </p>
            <p class="mt-0.5 text-2xs text-muted">
              Campo 799
            </p>
          </article>
        </div>

        <UAlert
          v-if="store.blockingFindings > 0"
          color="error"
          variant="soft"
          icon="i-lucide-triangle-alert"
          :title="`${store.blockingFindings} hallazgo(s) abierto(s) en el período`"
          description="Los comprobantes con totales inconsistentes, RUC inválido, empresa fantasma o secuencial duplicado bloquean el cierre de la declaración."
        >
          <template #actions>
            <UButton
              color="neutral"
              variant="outline"
              size="xs"
              label="Revisar hallazgos"
              :to="{ name: 'fiscal-findings' }"
            />
          </template>
        </UAlert>

        <UAlert
          v-if="draft && !draft.period.has_period && !store.loading"
          color="info"
          variant="soft"
          icon="i-lucide-info"
          title="Período sin comprobantes procesados"
          description="Sincroniza con el SRI o carga un XML autorizado; los agregados se calculan al cerrar la sincronización."
        />

        <section
          v-if="draft?.proportionality.proportionality_factor"
          class="rounded-lg border border-default bg-elevated/50 p-4"
        >
          <h2 class="text-sm font-medium text-highlighted">
            Proporcionalidad de IVA
          </h2>
          <p class="mt-0.5 text-xs text-muted">
            Factor del campo 563: ventas con tarifa 0 % sobre el total de ventas. Convención pendiente de firma
            del contador.
          </p>
          <dl class="mt-3 grid grid-cols-2 gap-3 text-xs sm:grid-cols-4">
            <div>
              <dt class="text-2xs uppercase tracking-wider text-dimmed">
                Ventas 0 %
              </dt>
              <dd class="mt-0.5 font-mono tabular-nums text-highlighted">
                {{ formatCents(draft.proportionality.sales_zero) }}
              </dd>
            </div>
            <div>
              <dt class="text-2xs uppercase tracking-wider text-dimmed">
                Ventas gravadas
              </dt>
              <dd class="mt-0.5 font-mono tabular-nums text-highlighted">
                {{ formatCents(draft.proportionality.sales_general) }}
              </dd>
            </div>
            <div>
              <dt class="text-2xs uppercase tracking-wider text-dimmed">
                Factor
              </dt>
              <dd class="mt-0.5 font-mono tabular-nums text-highlighted">
                {{ draft.proportionality.proportionality_factor }}
              </dd>
            </div>
            <div>
              <dt class="text-2xs uppercase tracking-wider text-dimmed">
                Crédito proporcional
              </dt>
              <dd class="mt-0.5 font-mono tabular-nums text-highlighted">
                {{ draft.proportionality.purchases_common_tax_to_credit === null
                  ? '—'
                  : formatCents(draft.proportionality.purchases_common_tax_to_credit) }}
              </dd>
            </div>
          </dl>
        </section>

        <div
          v-if="store.loading && draft === null"
          class="flex items-center justify-center py-12 text-muted"
          role="status"
          aria-live="polite"
        >
          Cargando el formulario…
        </div>

        <Form104SectionTable
          v-for="group in groups"
          v-else
          :key="group.key"
          :section="group"
          :loading="store.loading"
        />
      </template>
    </AppPage>
  </AppShell>
</template>
