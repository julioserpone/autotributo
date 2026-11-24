<script setup lang="ts">
import { computed } from 'vue';

import type { Dashboard } from '@/stores/dashboard';
import { formatCents, formatCount, periodLabel } from '@/lib/dashboard';

const props = defineProps<{
    dashboard: Dashboard;
    loading?: boolean;
}>();

const iva = computed(() => props.dashboard.iva);
const withholdings = computed(() => props.dashboard.withholdings);
const period = computed(() => periodLabel(props.dashboard.period.year, props.dashboard.period.month));

/** IVA neto: positivo es a pagar, negativo es crédito a favor del período. */
const netTone = computed(() => {
    if (iva.value.net_cents > 0) return 'text-amber-600 dark:text-amber-400';
    if (iva.value.net_cents < 0) return 'text-emerald-600 dark:text-emerald-400';

    return 'text-highlighted';
});

const netHint = computed(() => {
    if (iva.value.net_cents > 0) return 'Saldo estimado a pagar (Formulario 104)';
    if (iva.value.net_cents < 0) return 'Crédito tributario a favor';

    return 'Sin diferencia entre ventas y compras';
});

const cards = computed(() => [
    {
        title: 'IVA en ventas',
        hint: `Base imponible ${formatCents(iva.value.sales.base_cents)}`,
        value: formatCents(iva.value.sales.tax_cents),
        badge: formatCount(iva.value.sales.documents_count, 'comprobante', 'comprobantes'),
        icon: 'i-lucide-trending-up',
        tone: 'text-sky-500',
    },
    {
        title: 'IVA en compras',
        hint: `Base imponible ${formatCents(iva.value.purchases.base_cents)}`,
        value: formatCents(iva.value.purchases.tax_cents),
        badge: formatCount(iva.value.purchases.documents_count, 'comprobante', 'comprobantes'),
        icon: 'i-lucide-shopping-cart',
        tone: 'text-violet-500',
    },
    {
        title: 'IVA neto del período',
        hint: netHint.value,
        value: formatCents(iva.value.net_cents),
        badge: period.value,
        icon: 'i-lucide-scale',
        tone: 'text-amber-500',
        valueClass: netTone.value,
    },
    {
        title: 'Retenciones recibidas',
        hint: `Practicadas: ${formatCents(withholdings.value.practiced_cents)}`,
        value: formatCents(withholdings.value.received_cents),
        badge: formatCount(withholdings.value.received_documents, 'doc', 'docs'),
        icon: 'i-lucide-hand-coins',
        tone: 'text-emerald-500',
    },
]);
</script>

<template>
  <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <article
      v-for="card in cards"
      :key="card.title"
      class="flex flex-col justify-between rounded-lg border border-default bg-elevated/50 p-5"
    >
      <div class="flex items-start justify-between gap-3 border-b border-default pb-4">
        <div>
          <h2 class="text-sm font-medium text-highlighted">
            {{ card.title }}
          </h2>
          <p class="mt-0.5 text-xs text-muted">
            {{ card.hint }}
          </p>
        </div>
        <UIcon
          :name="card.icon"
          class="size-5 shrink-0"
          :class="card.tone"
        />
      </div>
      <div class="flex items-baseline justify-between gap-3 pt-4">
        <span
          v-if="loading"
          class="h-6 w-24 animate-pulse rounded bg-elevated"
        />
        <span
          v-else
          class="font-mono text-lg font-semibold tabular-nums"
          :class="card.valueClass ?? 'text-highlighted'"
        >
          {{ card.value }}
        </span>
        <span class="whitespace-nowrap rounded-full border border-default bg-elevated px-2.5 py-1 font-mono text-xs text-muted">
          {{ card.badge }}
        </span>
      </div>
    </article>
  </section>
</template>
