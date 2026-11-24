<script setup lang="ts">
import { computed } from 'vue';

import type { DashboardFindings, DashboardPersonalExpenseCap } from '@/stores/dashboard';
import { formatCents, severityLabel } from '@/lib/dashboard';

const props = defineProps<{
  findings: DashboardFindings;
  caps: DashboardPersonalExpenseCap[];
  year: number;
  loading?: boolean;
}>();

const severityOrder = ['critical', 'warning', 'info'] as const;

type SeverityRow = {
    severity: string;
    label: string;
    count: number;
    color: 'error' | 'warning' | 'info';
};

/** Color del badge de Nuxt UI según la severidad del hallazgo. */
function severityColor(severity: string): SeverityRow['color'] {
    if (severity === 'critical') return 'error';
    if (severity === 'warning') return 'warning';

    return 'info';
}

const severityRows = computed<SeverityRow[]>(() =>
    severityOrder
        .map((severity) => ({
            severity,
            label: severityLabel(severity),
            count: props.findings.by_severity[severity] ?? 0,
            color: severityColor(severity),
        }))
        .filter((row) => row.count > 0),
);

/** Los topes vienen por categoría y número de dependientes: se usa el máximo. */
const capsByCategory = computed(() => {
  const grouped = new Map<string, DashboardPersonalExpenseCap>();

  for (const cap of props.caps) {
    const current = grouped.get(cap.category_code);

    if (current === undefined || cap.cap_cents > current.cap_cents) {
      grouped.set(cap.category_code, cap);
    }
  }

  return [...grouped.values()].sort((a, b) => b.cap_cents - a.cap_cents);
});

</script>

<template>
  <section class="grid grid-cols-1 gap-4 lg:grid-cols-2">
    <article class="rounded-lg border border-default bg-elevated/50 p-5">
      <header class="flex items-start justify-between gap-3 border-b border-default pb-4">
        <div>
          <h2 class="text-sm font-medium text-highlighted">
            Alertas fiscales
          </h2>
          <p class="mt-0.5 text-xs text-muted">
            Hallazgos abiertos detectados sobre los comprobantes.
          </p>
        </div>
        <UBadge
          :color="findings.open_total > 0 ? 'error' : 'success'"
          variant="subtle"
          size="xs"
          class="shrink-0 gap-1.5 font-medium"
        >
          <span
            class="size-1.5 rounded-full"
            :class="findings.open_total > 0 ? 'bg-red-500' : 'bg-emerald-500'"
          />
          {{ findings.open_total }} abiertos
        </UBadge>
      </header>

      <div
        v-if="loading"
        class="mt-4 h-16 animate-pulse rounded bg-elevated"
      />

      <div
        v-else-if="findings.open_total === 0"
        class="mt-4 flex items-center gap-2 rounded-md border border-emerald-500/30 bg-emerald-500/10 px-3 py-2.5 text-xs text-emerald-700 dark:text-emerald-300"
      >
        <UIcon
          name="i-lucide-shield-check"
          class="size-4 shrink-0"
        />
        Sin hallazgos abiertos: los comprobantes del período no muestran riesgos.
      </div>

      <ul
        v-else
        class="mt-4 space-y-2"
      >
        <li
          v-for="row in severityRows"
          :key="row.severity"
          class="flex items-center justify-between rounded-md border border-default bg-elevated/60 px-3 py-2 text-xs"
        >
          <span class="text-muted">{{ row.label }}</span>
          <UBadge
            :color="row.color"
            variant="subtle"
            size="xs"
          >
            {{ row.count }}
          </UBadge>
        </li>
      </ul>

      <RouterLink
        :to="{ name: 'fiscal-findings' }"
        class="mt-4 inline-flex items-center gap-1 text-xs font-medium text-primary underline-offset-4 hover:underline"
      >
        Revisar hallazgos
        <UIcon
          name="i-lucide-arrow-right"
          class="size-3.5"
        />
      </RouterLink>
    </article>

    <article class="rounded-lg border border-default bg-elevated/50 p-5">
      <header class="border-b border-default pb-4">
        <h2 class="text-sm font-medium text-highlighted">
          Topes de gastos personales
        </h2>
        <p class="mt-0.5 text-xs text-muted">
          Límites vigentes por categoría y carga familiar.
        </p>
      </header>

      <div
        v-if="loading"
        class="mt-4 h-16 animate-pulse rounded bg-elevated"
      />

      <ul
        v-else-if="capsByCategory.length > 0"
        class="mt-4 divide-y divide-default"
      >
        <li
          v-for="cap in capsByCategory"
          :key="cap.category_code"
          class="flex items-center justify-between py-2 text-xs"
        >
          <div>
            <p class="font-medium capitalize text-highlighted">
              {{ cap.category_code }}
            </p>
            <p class="text-2xs text-dimmed">
              {{ cap.dependents }} dependientes
            </p>
          </div>
          <span class="font-mono tabular-nums text-muted">{{ formatCents(cap.cap_cents) }}</span>
        </li>
      </ul>

      <p
        v-else
        class="mt-4 text-xs text-muted"
      >
        No hay topes cargados para el año {{ year }}.
      </p>
    </article>
  </section>
</template>
