<script setup lang="ts">
import { computed } from 'vue';

import type { DashboardIvaBucket } from '@/stores/dashboard';
import { formatCents, monthLabel } from '@/lib/dashboard';

/**
 * IVA de ventas frente a compras del período seleccionado.
 *
 * El período se elige en la cabecera del panel (`DashboardPeriodPicker`); este
 * bloque solo compara, para que la navegación del período no dependa de una
 * tarjeta concreta.
 */
const props = defineProps<{
    sales: DashboardIvaBucket;
    purchases: DashboardIvaBucket;
    netCents: number;
    year?: number;
    month?: number;
}>();

const maxTax = computed(() => Math.max(props.sales.tax_cents, props.purchases.tax_cents, 1));

function barWidth(cents: number): string {
    return `${Math.round((cents / maxTax.value) * 100)}%`;
}

const caption = computed(() => (
    props.year && props.month
        ? `${monthLabel(props.month)} ${props.year} · comparación del impuesto liquidado`
        : 'Comparación del impuesto liquidado del período'
));
</script>

<template>
  <section class="rounded-lg border border-default bg-elevated/50 p-5">
    <header class="border-b border-default pb-4">
      <h2 class="text-sm font-medium text-highlighted">
        IVA ventas vs. compras
      </h2>
      <p class="mt-0.5 text-xs text-muted">
        {{ caption }}
      </p>
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
