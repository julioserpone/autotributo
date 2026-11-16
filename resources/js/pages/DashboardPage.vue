<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';

import AppShell from '@/layouts/AppShell.vue';
import InvoiceTable from '@/components/invoices/InvoiceTable.vue';
import { useCompanyStore } from '@/stores/company';
import { previewInvoices, previewKpis, PREVIEW_PERIOD } from '@/pages/dashboardPreview';

const route = useRoute();
const companyStore = useCompanyStore();

const isPreview = computed(() => route.query.preview === '1');

const syncOpen = ref(false);
const queryOpen = ref(false);

const periodLabel = computed(() => {
    if (isPreview.value) {
        return PREVIEW_PERIOD;
    }

    const now = new Date();
    return now.toLocaleDateString('es-EC', { month: 'long', year: 'numeric' }).replace(/^\w/, (c) => c.toUpperCase());
});

const periodBadge = computed(() => {
    const now = new Date();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    return `Período ${month}/${now.getFullYear()}`;
});

const invoices = computed(() => (isPreview.value ? previewInvoices : []));

const kpis = computed(() => {
    if (isPreview.value) {
        return previewKpis;
    }

    return { totalCents: 0, ivaCents: 0, withholdingsCents: 0, withholdingDocs: 0 };
});

function money(cents: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(cents / 100);
}

onMounted(() => {
    if (!companyStore.loaded) {
        void companyStore.fetchCompanies();
    }
});
</script>

<template>
  <AppShell title="Panel">
    <div
      v-if="isPreview"
      class="flex items-center gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-2.5 text-xs text-amber-700 dark:text-amber-300"
    >
      <UIcon
        name="i-lucide-eye"
        class="size-4 shrink-0"
      />
      <span>
        Vista previa con datos de ejemplo. Los valores son ficticios y no representan información fiscal real.
      </span>
      <RouterLink
        to="/"
        class="ml-auto font-medium underline-offset-4 hover:underline"
      >
        Salir de la vista previa
      </RouterLink>
    </div>

    <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <div class="flex items-center gap-2">
          <h1 class="text-lg font-semibold tracking-tight text-ink">
            Panel Tributario — {{ periodLabel }}
          </h1>
          <span class="rounded-md border border-line bg-elevated px-2 py-0.5 font-mono text-2xs text-muted">
            {{ periodBadge }}
          </span>
        </div>
        <p class="mt-1 text-xs text-muted">
          Gestión y sincronización automática de comprobantes recibidos y emitidos ante el SRI.
        </p>
      </div>

      <div class="flex items-center gap-2">
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-refresh-cw"
          label="Sincronizar SRI"
          @click="syncOpen = true"
        />
        <UButton
          color="neutral"
          variant="solid"
          size="sm"
          icon="i-lucide-plus"
          label="Nueva consulta"
          @click="queryOpen = true"
        />
      </div>
    </header>

    <section class="grid grid-cols-1 gap-4 md:grid-cols-3">
      <article class="flex flex-col justify-between rounded-lg border border-line bg-surface p-5 shadow-2xs dark:shadow-none">
        <div class="flex items-center justify-between border-b border-line pb-4">
          <div>
            <h2 class="text-sm font-medium text-ink">
              Comprobantes Electrónicos
            </h2>
            <p class="mt-0.5 text-xs text-muted">
              Sincronización automática con SRI
            </p>
          </div>
          <UButton
            color="neutral"
            variant="solid"
            size="xs"
            label="Nueva consulta"
            @click="queryOpen = true"
          />
        </div>
        <div class="flex items-baseline justify-between pt-4">
          <span class="text-xs text-muted">Total declarado (Mes)</span>
          <span class="font-mono text-lg font-semibold tabular-nums text-ink">
            {{ isPreview ? money(kpis.totalCents) : '—' }}
          </span>
        </div>
      </article>

      <article class="flex flex-col justify-between rounded-lg border border-line bg-surface p-5 shadow-2xs dark:shadow-none">
        <div class="flex items-center justify-between border-b border-line pb-4">
          <div>
            <h2 class="text-sm font-medium text-ink">
              IVA Liquidado (15%)
            </h2>
            <p class="mt-0.5 text-xs text-muted">
              Crédito tributario aplicado
            </p>
          </div>
          <UBadge
            color="success"
            variant="subtle"
            size="xs"
            class="gap-1.5 font-medium"
          >
            <span class="size-1.5 rounded-full bg-emerald-500" />
            Al día
          </UBadge>
        </div>
        <div class="flex items-baseline justify-between pt-4">
          <span class="text-xs text-muted">Saldo estimado Formulario 104</span>
          <span class="font-mono text-lg font-semibold tabular-nums text-ink">
            {{ isPreview ? money(kpis.ivaCents) : '—' }}
          </span>
        </div>
      </article>

      <article class="flex flex-col justify-between rounded-lg border border-line bg-surface p-5 shadow-2xs dark:shadow-none">
        <div class="flex items-center justify-between border-b border-line pb-4">
          <div>
            <h2 class="text-sm font-medium text-ink">
              Retenciones Recibidas
            </h2>
            <p class="mt-0.5 text-xs text-muted">
              Renta e IVA del período
            </p>
          </div>
          <span class="rounded-md border border-line bg-elevated px-2 py-0.5 font-mono text-2xs text-muted">
            {{ isPreview ? `${kpis.withholdingDocs} docs` : '0 docs' }}
          </span>
        </div>
        <div class="flex items-baseline justify-between pt-4">
          <span class="text-xs text-muted">Total retenido a favor</span>
          <span class="font-mono text-lg font-semibold tabular-nums text-ink">
            {{ isPreview ? money(kpis.withholdingsCents) : '—' }}
          </span>
        </div>
      </article>
    </section>

    <InvoiceTable :invoices="invoices" />

    <UAlert
      v-if="!isPreview"
      color="warning"
      variant="soft"
      icon="i-lucide-alert-triangle"
      title="Sin datos todavía"
      description="Los comprobantes aparecerán aquí cuando sincronices con el SRI o cargues un XML autorizado. La bandeja completa llega con el siguiente paso (F4-05)."
    />

    <UModal
      v-model:open="syncOpen"
      title="Sincronizar con el SRI"
      description="Descarga los comprobantes del período seleccionado desde el portal del SRI."
    >
      <template #body>
        <p class="text-sm text-muted">
          Esta acción se habilita cuando exista una empresa activa con credenciales SRI configuradas.
        </p>
        <UAlert
          v-if="companyStore.requiresTwoFactorSetup"
          color="warning"
          variant="soft"
          icon="i-lucide-shield-alert"
          title="Verificación en dos pasos requerida"
          description="Activa la verificación en dos pasos para acceder a los endpoints de negocio."
          class="mt-3"
        >
          <template #actions>
            <UButton
              color="neutral"
              variant="outline"
              size="xs"
              label="Configurar"
              :to="{ name: 'two-factor-setup' }"
            />
          </template>
        </UAlert>
      </template>
      <template #footer>
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          label="Cerrar"
          @click="syncOpen = false"
        />
      </template>
    </UModal>

    <UModal
      v-model:open="queryOpen"
      title="Nueva consulta de comprobante SRI"
      description="Ingresa la clave de acceso de 49 dígitos o adjunta un XML autorizado."
    >
      <template #body>
        <p class="text-sm text-muted">
          La carga manual de comprobantes desde XML se habilita en el siguiente paso (F4-05).
        </p>
      </template>
      <template #footer>
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          label="Cerrar"
          @click="queryOpen = false"
        />
      </template>
    </UModal>
  </AppShell>
</template>
