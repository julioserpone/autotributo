<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';

import { getLocalTimeZone, parseDate, today } from '@internationalized/date';

import AppShell from '@/layouts/AppShell.vue';
import AppPage from '@/components/layout/AppPage.vue';
import InvoiceTable from '@/components/invoices/InvoiceTable.vue';
import { useCompanyStore } from '@/stores/company';
import { useInvoiceStore, documentTypeLabels } from '@/stores/invoices';

const companyStore = useCompanyStore();
const invoiceStore = useInvoiceStore();

// Filtros de fecha como selector de calendario (UInputDate) sincronizados con
// los strings `YYYY-MM-DD` que espera el store y el endpoint.
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const dateFrom = ref<any>(null);
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const dateTo = ref<any>(null);

const todayDate = today(getLocalTimeZone());

const fromOpen = ref(false);
const toOpen = ref(false);
const fromWrap = ref<HTMLElement>();
const toWrap = ref<HTMLElement>();

// eslint-disable-next-line @typescript-eslint/no-explicit-any
function toDateValue(value: string): any {
    if (!value) {
        return null;
    }

    try {
        return parseDate(value);
    } catch {
        return null;
    }
}

watch(() => invoiceStore.filters.dateFrom, (value) => {
    dateFrom.value = toDateValue(value);
}, { immediate: true });

watch(() => invoiceStore.filters.dateTo, (value) => {
    dateTo.value = toDateValue(value);
}, { immediate: true });

watch(dateFrom, (value) => {
    invoiceStore.filters.dateFrom = value ? value.toString() : '';
});

watch(dateTo, (value) => {
    invoiceStore.filters.dateTo = value ? value.toString() : '';
});

// Cierra el popover del calendario al seleccionar una fecha.
watch(dateFrom, () => {
    fromOpen.value = false;
});

watch(dateTo, () => {
    toOpen.value = false;
});

// eslint-disable-next-line @typescript-eslint/no-explicit-any
function onDateFromSelect(value: any): void {
    dateFrom.value = value;
    fromOpen.value = false;
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
function onDateToSelect(value: any): void {
    dateTo.value = value;
    toOpen.value = false;
}

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
  <AppShell>
    <AppPage
      title="Bandeja de comprobantes"
      description="Comprobantes recibidos y emitidos ante el SRI, con filtros y detalle por documento."
    >
      <!-- Filtros -->
      <section class="rounded-lg border border-default bg-elevated/50 p-4">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <UFormField
            label="Empresa"
            name="company"
          >
            <USelect
              v-model="invoiceStore.filters.companyId"
              :items="companyOptions"
              color="neutral"
              variant="outline"
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
              color="neutral"
              variant="outline"
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
              color="neutral"
              variant="outline"
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
              color="neutral"
              variant="outline"
              size="md"
              class="w-full"
            />
          </UFormField>
        </div>

        <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <UFormField
            label="Desde"
            name="date_from"
          >
            <div
              ref="fromWrap"
              class="relative w-full"
            >
              <UInputDate
                v-model="dateFrom"
                :range="false"
                color="neutral"
                variant="outline"
                size="md"
                class="w-full"
              >
                <template #trailing>
                  <button
                    type="button"
                    class="text-dimmed hover:text-highlighted"
                    aria-label="Abrir calendario"
                    @click="fromOpen = true"
                  >
                    <UIcon
                      name="i-lucide-calendar"
                      class="size-4"
                    />
                  </button>
                </template>
              </UInputDate>

              <UPopover
                v-if="fromOpen"
                :open="true"
                :reference="fromWrap"
                :content="{ side: 'bottom', align: 'start' }"
                @update:open="fromOpen = $event"
              >
                <template #content>
                  <UCalendar
                    :model-value="dateFrom"
                    :default-placeholder="todayDate"
                    :max-value="todayDate"
                    :prevent-deselect="true"
                    class="p-2"
                    @update:model-value="onDateFromSelect"
                  />
                </template>
              </UPopover>
            </div>
          </UFormField>

          <UFormField
            label="Hasta"
            name="date_to"
          >
            <div
              ref="toWrap"
              class="relative w-full"
            >
              <UInputDate
                v-model="dateTo"
                :range="false"
                :max-value="todayDate"
                color="neutral"
                variant="outline"
                size="md"
                class="w-full"
              >
                <template #trailing>
                  <button
                    type="button"
                    class="text-dimmed hover:text-highlighted"
                    aria-label="Abrir calendario"
                    @click="toOpen = true"
                  >
                    <UIcon
                      name="i-lucide-calendar"
                      class="size-4"
                    />
                  </button>
                </template>
              </UInputDate>

              <UPopover
                v-if="toOpen"
                :open="true"
                :reference="toWrap"
                :content="{ side: 'bottom', align: 'start' }"
                @update:open="toOpen = $event"
              >
                <template #content>
                  <UCalendar
                    :model-value="dateTo"
                    :default-placeholder="todayDate"
                    :max-value="todayDate"
                    :prevent-deselect="true"
                    class="p-2"
                    @update:model-value="onDateToSelect"
                  />
                </template>
              </UPopover>
            </div>
          </UFormField>

          <div class="flex items-end col-span-2 lg:col-span-1">
            <UButton
              color="primary"
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
        class="flex items-center justify-between rounded-lg border border-default bg-elevated px-4 py-2 text-xs text-highlighted"
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
                <dt class="text-2xs uppercase tracking-wider text-dimmed">
                  Número
                </dt>
                <dd class="font-mono text-highlighted">
                  {{ detailInvoice.number }}
                </dd>
              </div>
              <div>
                <dt class="text-2xs uppercase tracking-wider text-dimmed">
                  Tipo
                </dt>
                <dd class="text-highlighted">
                  {{ documentTypeLabels[detailInvoice.document_type] }}
                </dd>
              </div>
              <div>
                <dt class="text-2xs uppercase tracking-wider text-dimmed">
                  Emisor
                </dt>
                <dd class="text-highlighted">
                  {{ detailInvoice.issuer_name }}
                </dd>
              </div>
              <div>
                <dt class="text-2xs uppercase tracking-wider text-dimmed">
                  RUC emisor
                </dt>
                <dd class="font-mono text-highlighted">
                  {{ detailInvoice.issuer_ruc }}
                </dd>
              </div>
              <div>
                <dt class="text-2xs uppercase tracking-wider text-dimmed">
                  Fecha emisión
                </dt>
                <dd class="text-highlighted">
                  {{ detailInvoice.issue_date }}
                </dd>
              </div>
              <div>
                <dt class="text-2xs uppercase tracking-wider text-dimmed">
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

            <div class="rounded-lg border border-default bg-muted/50 p-3">
              <div class="flex items-baseline justify-between">
                <span class="text-xs text-muted">Subtotal</span>
                <span class="font-mono tabular-nums text-highlighted">{{ money(detailInvoice.subtotal_cents) }}</span>
              </div>
              <div class="flex items-baseline justify-between">
                <span class="text-xs text-muted">IVA 15%</span>
                <span class="font-mono tabular-nums text-highlighted">{{ money(detailInvoice.tax_cents) }}</span>
              </div>
              <div class="flex items-baseline justify-between border-t border-default pt-2 font-semibold">
                <span class="text-xs text-muted">Total</span>
                <span class="font-mono tabular-nums text-highlighted">{{ money(detailInvoice.total_cents) }}</span>
              </div>
            </div>

            <div>
              <dt class="text-2xs uppercase tracking-wider text-dimmed">
                Clave de acceso
              </dt>
              <dd class="mt-1 break-all font-mono text-2xs text-muted">
                {{ detailInvoice.access_key }}
              </dd>
            </div>
          </div>
        </template>
      </USlideover>
    </AppPage>
  </AppShell>
</template>
