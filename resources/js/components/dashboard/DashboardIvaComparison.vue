<script setup lang="ts">
import { computed } from 'vue';

import type { DashboardIvaBucket } from '@/stores/dashboard';
import { formatCents, monthLabel, periodLabel } from '@/lib/dashboard';

const props = defineProps<{
    year: number;
    month: number;
    sales: DashboardIvaBucket;
    purchases: DashboardIvaBucket;
    netCents: number;
}>();

const emit = defineEmits<{
    (e: 'change-period', payload: { year: number; month: number }): void;
}>();

const maxTax = computed(() => Math.max(props.sales.tax_cents, props.purchases.tax_cents, 1));

function barWidth(cents: number): string {
    return `${Math.round((cents / maxTax.value) * 100)}%`;
}

function shift(delta: number): void {
    const date = new Date(props.year, props.month - 1 + delta, 1);
    emit('change-period', { year: date.getFullYear(), month: date.getMonth() + 1 });
}

const periodOptions = computed(() => {
    const options: { value: string; label: string }[] = [];

    for (let index = 0; index < 12; index += 1) {
        const date = new Date(props.year, props.month - 1 - index, 1);
        options.push({
            value: `${date.getFullYear()}-${date.getMonth() + 1}`,
            label: periodLabel(date.getFullYear(), date.getMonth() + 1),
        });
    }

    return options;
});

function selectPeriod(value: string): void {
    const [rawYear, rawMonth] = value.split('-').map(Number);

    if (rawYear === undefined || rawMonth === undefined) return;

    emit('change-period', { year: rawYear, month: rawMonth });
}
</script>

<template>
  <section class="rounded-lg border border-default bg-elevated/50 p-5">
    <header class="flex flex-wrap items-start justify-between gap-3 border-b border-default pb-4">
      <div>
        <h2 class="text-sm font-medium text-highlighted">
          IVA ventas vs. compras
        </h2>
        <p class="mt-0.5 text-xs text-muted">
          {{ monthLabel(month) }} {{ year }} · comparación del impuesto liquidado
        </p>
      </div>
      <div class="flex items-center gap-2">
        <UButton
          color="neutral"
          variant="outline"
          size="xs"
          icon="i-lucide-chevron-left"
          label="Mes anterior"
          @click="shift(-1)"
        />
        <select
          class="rounded-md border border-default bg-elevated px-2.5 py-1.5 text-xs text-highlighted focus:border-accented focus:outline-none"
          :value="`${year}-${month}`"
          aria-label="Período fiscal"
          @change="selectPeriod(($event.target as HTMLSelectElement).value)"
        >
          <option
            v-for="option in periodOptions"
            :key="option.value"
            :value="option.value"
          >
            {{ option.label }}
          </option>
        </select>
        <UButton
          color="neutral"
          variant="outline"
          size="xs"
          icon="i-lucide-chevron-right"
          label="Mes siguiente"
          @click="shift(1)"
        />
      </div>
    </header>

    <div class="space-y-5 pt-5">
      <div>
        <div class="flex items-baseline justify-between text-xs">
          <span class="font-medium text-highlighted">Ventas</span>
          <span class="font-mono tabular-nums text-muted">{{ formatCents(sales.tax_cents) }}</span>
        </div>
        <div class="mt-1.5 h-2.5 w-full overflow-hidden rounded-full bg-elevated">
          <div
            class="h-full rounded-full bg-sky-500 transition-all"
            :style="{ width: barWidth(sales.tax_cents) }"
          />
        </div>
        <p class="mt-1 text-2xs text-dimmed">
          Base {{ formatCents(sales.base_cents) }} · {{ sales.documents_count }} comprobantes
        </p>
      </div>

      <div>
        <div class="flex items-baseline justify-between text-xs">
          <span class="font-medium text-highlighted">Compras</span>
          <span class="font-mono tabular-nums text-muted">{{ formatCents(purchases.tax_cents) }}</span>
        </div>
        <div class="mt-1.5 h-2.5 w-full overflow-hidden rounded-full bg-elevated">
          <div
            class="h-full rounded-full bg-violet-500 transition-all"
            :style="{ width: barWidth(purchases.tax_cents) }"
          />
        </div>
        <p class="mt-1 text-2xs text-dimmed">
          Base {{ formatCents(purchases.base_cents) }} · {{ purchases.documents_count }} comprobantes
        </p>
      </div>

      <div class="flex items-baseline justify-between rounded-md border border-default bg-elevated/60 px-3 py-2.5 text-xs">
        <span class="text-muted">IVA neto del período</span>
        <span class="font-mono font-semibold tabular-nums text-highlighted">{{ formatCents(netCents) }}</span>
      </div>
    </div>
  </section>
</template>
