<script setup lang="ts">
import { computed, ref } from 'vue';

export type FiscalDocumentStatus = 'authorized' | 'pending' | 'rejected';

export interface FiscalDocumentRow {
    id: string;
    number: string;
    documentType: string;
    issuerName: string;
    issuerRuc: string;
    issueDate: string;
    subtotalCents: number;
    taxCents: number;
    totalCents: number;
    status: FiscalDocumentStatus;
}

const props = defineProps<{
    fiscalDocuments: FiscalDocumentRow[];
    loading?: boolean;
    selectable?: boolean;
    selected?: string[];
}>();

const emit = defineEmits<{
    (e: 'update:selected', value: string[]): void;
    (e: 'row-click', id: string): void;
}>();

const search = ref('');
const statusFilter = ref<'all' | FiscalDocumentStatus>('all');

const filtered = computed(() => {
    const query = search.value.trim().toLowerCase();
    return props.fiscalDocuments.filter((fiscal_document) => {
        const matchesStatus = statusFilter.value === 'all' || fiscal_document.status === statusFilter.value;
        const matchesSearch = query === ''
            || fiscal_document.issuerName.toLowerCase().includes(query)
            || fiscal_document.issuerRuc.includes(query)
            || fiscal_document.number.includes(query);
        return matchesStatus && matchesSearch;
    });
});

const counts = computed(() => ({
    all: props.fiscalDocuments.length,
    authorized: props.fiscalDocuments.filter((item) => item.status === 'authorized').length,
    pending: props.fiscalDocuments.filter((item) => item.status === 'pending').length,
    rejected: props.fiscalDocuments.filter((item) => item.status === 'rejected').length,
}));

function money(cents: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(cents / 100);
}

function statusColor(status: FiscalDocumentStatus): 'success' | 'warning' | 'error' {
    switch (status) {
        case 'authorized':
            return 'success';
        case 'pending':
            return 'warning';
        default:
            return 'error';
    }
}

function statusLabel(status: FiscalDocumentStatus): string {
    switch (status) {
        case 'authorized':
            return 'Autorizado';
        case 'pending':
            return 'Pendiente';
        default:
            return 'Rechazado';
    }
}

function toggleAll(event: Event): void {
    const checked = (event.target as HTMLInputElement).checked;
    emit('update:selected', checked ? props.fiscalDocuments.map((fiscal_document) => fiscal_document.id) : []);
}

function toggleOne(id: string): void {
    const current = props.selected ?? [];
    const next = current.includes(id)
        ? current.filter((item) => item !== id)
        : [...current, id];
    emit('update:selected', next);
}

function setFilter(next: 'all' | FiscalDocumentStatus): void {
    statusFilter.value = next;
}
</script>

<template>
  <section class="overflow-hidden rounded-lg border border-default bg-elevated/50">
    <div class="flex flex-col gap-3 border-b border-default p-4 sm:flex-row sm:items-center sm:justify-between">
      <div class="flex items-center gap-1 rounded-md border border-default bg-elevated p-1">
        <button
          v-for="filter in [
            { key: 'all', label: 'Todos' },
            { key: 'authorized', label: 'Autorizados' },
            { key: 'pending', label: 'Pendientes' },
            { key: 'rejected', label: 'Rechazados' },
          ]"
          :key="filter.key"
          type="button"
          class="rounded px-2.5 py-1 text-xs font-medium transition"
          :class="statusFilter === filter.key
            ? 'border border-default bg-default text-highlighted'
            : 'text-muted hover:text-highlighted'"
          @click="setFilter(filter.key as 'all' | FiscalDocumentStatus)"
        >
          {{ filter.label }}
          <span
            v-if="filter.key === 'all'"
            class="ml-1 font-mono text-2xs text-dimmed"
          >
            {{ counts.all }}
          </span>
        </button>
      </div>

      <div class="relative w-full sm:w-64">
        <UIcon
          name="i-lucide-search"
          class="pointer-events-none absolute left-3 top-1/2 size-3.5 -translate-y-1/2 text-dimmed"
        />
        <input
          v-model="search"
          type="search"
          placeholder="Buscar RUC, proveedor o factura…"
          class="w-full rounded-md border border-default bg-elevated py-1.5 pl-8 pr-3 text-xs text-highlighted placeholder:text-dimmed focus:border-accented focus:outline-none"
        >
      </div>
    </div>

    <div class="overflow-x-auto">
      <table class="w-full text-left text-xs">
        <thead class="border-b border-default bg-elevated/60 text-2xs font-medium uppercase tracking-wider text-muted">
          <tr>
            <th
              v-if="props.selectable"
              class="w-10 px-4 py-2.5"
            >
              <input
                type="checkbox"
                class="size-3.5 rounded border-default bg-elevated"
                :checked="props.selected?.length === props.fiscalDocuments.length && props.fiscalDocuments.length > 0"
                @change="toggleAll"
                @click.stop
              >
            </th>
            <th class="px-4 py-2.5">
              Comprobante
            </th>
            <th class="px-4 py-2.5">
              Emisor / RUC
            </th>
            <th class="px-4 py-2.5">
              Fecha emisión
            </th>
            <th class="px-4 py-2.5 text-right">
              Subtotal
            </th>
            <th class="px-4 py-2.5 text-right">
              IVA 15%
            </th>
            <th class="px-4 py-2.5 text-right">
              Total
            </th>
            <th class="px-4 py-2.5">
              Estado SRI
            </th>
            <th class="px-4 py-2.5 text-right">
              Acciones
            </th>
          </tr>
        </thead>
        <tbody class="divide-y divide-default">
          <tr
            v-for="fiscal_document in filtered"
            :key="fiscal_document.id"
            class="cursor-pointer transition hover:bg-elevated/60"
            @click="emit('row-click', fiscal_document.id)"
          >
            <td
              v-if="props.selectable"
              class="px-4 py-3"
              @click.stop
            >
              <input
                type="checkbox"
                class="size-3.5 rounded border-default bg-elevated"
                :checked="props.selected?.includes(fiscal_document.id)"
                @change="toggleOne(fiscal_document.id)"
                @click.stop
              >
            </td>
            <td class="px-4 py-3">
              <p class="font-mono font-medium text-highlighted">
                {{ fiscal_document.number }}
              </p>
              <p class="text-2xs text-dimmed">
                {{ fiscal_document.documentType }}
              </p>
            </td>
            <td class="px-4 py-3">
              <p class="font-medium text-highlighted">
                {{ fiscal_document.issuerName }}
              </p>
              <p class="font-mono text-2xs text-muted">
                {{ fiscal_document.issuerRuc }}
              </p>
            </td>
            <td class="px-4 py-3 text-muted">
              {{ fiscal_document.issueDate }}
            </td>
            <td class="px-4 py-3 text-right font-mono tabular-nums text-muted">
              {{ money(fiscal_document.subtotalCents) }}
            </td>
            <td class="px-4 py-3 text-right font-mono tabular-nums text-muted">
              {{ money(fiscal_document.taxCents) }}
            </td>
            <td class="px-4 py-3 text-right font-mono tabular-nums font-semibold text-highlighted">
              {{ money(fiscal_document.totalCents) }}
            </td>
            <td class="px-4 py-3">
              <UBadge
                :color="statusColor(fiscal_document.status)"
                variant="subtle"
                size="xs"
                class="gap-1.5 font-medium"
              >
                <span
                  class="size-1.5 rounded-full"
                  :class="{
                    'bg-emerald-500': fiscal_document.status === 'authorized',
                    'bg-amber-500': fiscal_document.status === 'pending',
                    'bg-red-500': fiscal_document.status === 'rejected',
                  }"
                />
                {{ statusLabel(fiscal_document.status) }}
              </UBadge>
            </td>
            <td class="px-4 py-3 text-right">
              <UButton
                color="neutral"
                variant="outline"
                size="xs"
                icon="i-lucide-file-down"
                label="XML / RIDE"
              />
            </td>
          </tr>

          <tr v-if="filtered.length === 0">
            <td
              :colspan="props.selectable ? 9 : 8"
              class="px-4 py-10 text-center text-xs text-muted"
            >
              {{ props.fiscalDocuments.length === 0
                ? 'Aún no hay comprobantes en este período.'
                : 'No se encontraron comprobantes que coincidan con el filtro.' }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="flex items-center justify-between border-t border-default bg-elevated/30 px-4 py-3 text-xs text-muted">
      <span>Mostrando {{ filtered.length }} de {{ props.fiscalDocuments.length }} comprobantes</span>
      <span class="text-2xs">Cifras en USD · formato tabular</span>
    </div>
  </section>
</template>
