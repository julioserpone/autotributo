<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';

import AppShell from '@/layouts/AppShell.vue';
import InvoiceTable from '@/components/invoices/InvoiceTable.vue';
import { useCompanyStore } from '@/stores/company';
import { useInvoiceStore, documentTypeLabels } from '@/stores/invoices';

const companyStore = useCompanyStore();
const invoiceStore = useInvoiceStore();

const selected = ref<string[]>([]);
const detailOpen = ref(false);
const detailInvoice = ref<import('@/stores/invoices').InvoiceApi | null>(null);

const detailStatusColor = computed<'success' | 'warning' | 'error'>(() => {
    if (detailInvoice.value?.status === 'pending') return 'warning';
    if (detailInvoice.value?.status === 'rejected') return 'error';
    return 'success';
});

const detailStatusLabel = computed(() => {
    if (detailInvoice.value?.status === 'pending') return 'Pendiente';
    if (detailInvoice.value?.status === 'rejected') return 'Rechazado';
    return 'Autorizado';
});

const documentTypeOptions = [
    { label: 'Todos los tipos', value: 'all' },
    { label: 'Factura', value: '01' },
    { label: 'Liquidación', value: '03' },
    { label: 'Nota de crédito', value: '04' },
    { label: 'Nota de débito', value: '05' },
    { label: 'Guía de remisión', value: '06' },
    { label: 'Retención', value: '07' },
];

const statusOptions = [
    { label: 'Todos', value: 'all' },
    { label: 'Autorizados', value: 'authorized' },
    { label: 'Pendientes', value: 'pending' },
    { label: 'Rechazados', value: 'rejected' },
];

const companyOptions = computed(() => [
    { label: 'Todas las empresas', value: 'all' },
    ...companyStore.companies.map((company) => ({
        label: company.trade_name || company.legal_name,
        value: company.id,
    })),
]);

const money = (cents: number): string =>
    new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(cents / 100);

function openDetail(id: string): void {
    detailInvoice.value = invoiceStore.invoices.find((invoice) => invoice.id === id) ?? null;
    detailOpen.value = true;
}

function applyFilters(): void {
    void invoiceStore.fetchFirst();
}

onMounted(() => {
    if (!companyStore.loaded) {
        void companyStore.fetchCompanies();
    }
    void invoiceStore.fetchFirst();
});
</script>

<template>
  <AppShell title="Comprobantes">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h1 class="text-lg font-semibold tracking-tight text-ink">
          Bandeja de comprobantes
        </h1>
        <p class="mt-1 text-xs text-muted">
          Comprobantes recibidos y emitidos ante el SRI, con filtros y detalle por documento.
        </p>
      </div>
    </header>

    <!-- Filtros -->
    <section class="rounded-lg border border-line bg-surface p-4 shadow-2xs dark:shadow-none">
      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <UFormField
          label="Empresa"
          name="company"
        >
          <USelect
            v-model="invoiceStore.filters.companyId"
            :items="companyOptions"
            size="md"
            class="w-full"
          />
        </UFormField>

        <UFormField
          label="Estado"
          name="status"
        >
          <USelect
            v-model="invoiceStore.filters.status"
            :items="statusOptions"
            size="md"
            class="w-full"
          />
        </UFormField>

        <UFormField
          label="Tipo"
          name="document_type"
        >
          <USelect
            v-model="invoiceStore.filters.documentType"
            :items="documentTypeOptions"
            size="md"
            class="w-full"
          />
        </UFormField>

        <UFormField
          label="Emisor / RUC"
          name="issuer"
        >
          <UInput
            v-model="invoiceStore.filters.issuer"
            placeholder="Buscar…"
            icon="i-lucide-search"
            size="md"
            class="w-full"
          />
        </UFormField>
      </div>

      <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <UFormField
          label="Desde"
          name="date_from"
        >
          <UInput
            v-model="invoiceStore.filters.dateFrom"
            type="date"
            size="md"
            class="w-full"
          />
        </UFormField>

        <UFormField
          label="Hasta"
          name="date_to"
        >
          <UInput
            v-model="invoiceStore.filters.dateTo"
            type="date"
            size="md"
            class="w-full"
          />
        </UFormField>

        <div class="flex items-end">
          <UButton
            color="neutral"
            variant="solid"
            size="md"
            icon="i-lucide-filter"
            label="Aplicar filtros"
            block
            @click="applyFilters"
          />
        </div>
      </div>
    </section>

    <UAlert
      v-if="invoiceStore.error"
      color="error"
      variant="soft"
      icon="i-lucide-alert-circle"
      :description="invoiceStore.error"
    />

    <!-- Barra de selección masiva -->
    <div
      v-if="selected.length > 0"
      class="flex items-center justify-between rounded-lg border border-line bg-elevated px-4 py-2 text-xs text-ink"
    >
      <span>{{ selected.length }} seleccionados</span>
      <UButton
        color="neutral"
        variant="ghost"
        size="xs"
        label="Limpiar"
        @click="selected = []"
      />
    </div>

    <InvoiceTable
      v-model:selected="selected"
      :invoices="invoiceStore.rows"
      :loading="invoiceStore.loading"
      selectable
      @row-click="openDetail"
    />

    <div
      v-if="invoiceStore.nextCursor"
      class="flex justify-center"
    >
      <UButton
        color="neutral"
        variant="outline"
        size="md"
        :loading="invoiceStore.loadingMore"
        label="Cargar más"
        @click="invoiceStore.loadMore()"
      />
    </div>

    <UAlert
      v-if="!invoiceStore.loading && invoiceStore.rows.length === 0"
      color="warning"
      variant="soft"
      icon="i-lucide-alert-triangle"
      title="Sin comprobantes"
      description="No hay comprobantes que coincidan con los filtros aplicados."
    />

    <!-- Drawer de detalle -->
    <USlideover
      v-model:open="detailOpen"
      side="right"
      :ui="{ content: 'max-w-xl z-30', header: 'border-b-0' }"
      title="Detalle del comprobante"
    >
      <template #body>
        <div
          v-if="detailInvoice"
          class="space-y-4 text-sm"
        >
          <dl class="grid grid-cols-2 gap-3">
            <div>
              <dt class="text-2xs uppercase tracking-wider text-faint">
                Número
              </dt>
              <dd class="font-mono text-ink">
                {{ detailInvoice.number }}
              </dd>
            </div>
            <div>
              <dt class="text-2xs uppercase tracking-wider text-faint">
                Tipo
              </dt>
              <dd class="text-ink">
                {{ documentTypeLabels[detailInvoice.document_type] }}
              </dd>
            </div>
            <div>
              <dt class="text-2xs uppercase tracking-wider text-faint">
                Emisor
              </dt>
              <dd class="text-ink">
                {{ detailInvoice.issuer_name }}
              </dd>
            </div>
            <div>
              <dt class="text-2xs uppercase tracking-wider text-faint">
                RUC emisor
              </dt>
              <dd class="font-mono text-ink">
                {{ detailInvoice.issuer_ruc }}
              </dd>
            </div>
            <div>
              <dt class="text-2xs uppercase tracking-wider text-faint">
                Fecha emisión
              </dt>
              <dd class="text-ink">
                {{ detailInvoice.issue_date }}
              </dd>
            </div>
            <div>
              <dt class="text-2xs uppercase tracking-wider text-faint">
                Estado SRI
              </dt>
              <dd class="mt-1">
                <UBadge
                  :color="detailStatusColor"
                  variant="subtle"
                  size="xs"
                  class="gap-1.5 font-medium"
                >
                  <span
                    class="size-1.5 rounded-full"
                    :class="{
                      'bg-emerald-500': detailInvoice.status === 'authorized',
                      'bg-amber-500': detailInvoice.status === 'pending',
                      'bg-red-500': detailInvoice.status === 'rejected',
                    }"
                  />
                  {{ detailStatusLabel }}
                </UBadge>
              </dd>
            </div>
          </dl>

          <div class="rounded-lg border border-line bg-canvas p-3">
            <div class="flex items-baseline justify-between">
              <span class="text-xs text-muted">Subtotal</span>
              <span class="font-mono tabular-nums text-ink">{{ money(detailInvoice.subtotal_cents) }}</span>
            </div>
            <div class="flex items-baseline justify-between">
              <span class="text-xs text-muted">IVA 15%</span>
              <span class="font-mono tabular-nums text-ink">{{ money(detailInvoice.tax_cents) }}</span>
            </div>
            <div class="flex items-baseline justify-between border-t border-line pt-2 font-semibold">
              <span class="text-xs text-muted">Total</span>
              <span class="font-mono tabular-nums text-ink">{{ money(detailInvoice.total_cents) }}</span>
            </div>
          </div>

          <div>
            <dt class="text-2xs uppercase tracking-wider text-faint">
              Clave de acceso
            </dt>
            <dd class="mt-1 break-all font-mono text-2xs text-muted">
              {{ detailInvoice.access_key }}
            </dd>
          </div>
        </div>
      </template>
    </USlideover>
  </AppShell>
</template>
