<script setup lang="ts">
import { computed } from 'vue';

import type { FiscalDocumentItemApi } from '@/stores/fiscalDocuments';

/**
 * Líneas del comprobante con su clasificación (F3-05).
 *
 * Muestra, por ítem, el estado de clasificación, la categoría asignada y —si
 * existe— la confianza del clasificador. Así se ve en la bandeja lo que el
 * motor de clasificación decidió, sin pasar por la cola de revisión.
 */
const props = defineProps<{
    items: FiscalDocumentItemApi[];
    loading?: boolean;
}>();

type StatusMeta = {
    label: string;
    color: 'success' | 'warning' | 'error' | 'neutral' | 'info';
    hint: string;
};

const STATUS: Record<string, StatusMeta> = {
    auto: {
        label: 'Automática',
        color: 'success',
        hint: 'Clasificada por una regla o por la IA con confianza suficiente.',
    },
    review: {
        label: 'En revisión',
        color: 'warning',
        hint: 'La IA propuso una categoría con confianza baja: requiere revisión.',
    },
    pending: {
        label: 'Sin clasificar',
        color: 'neutral',
        hint: 'No hay regla que aplique ni sugerencia de la IA.',
    },
    manual: {
        label: 'Revisada',
        color: 'info',
        hint: 'Validada manualmente por un usuario.',
    },
};

const METHOD_LABELS: Record<string, string> = {
    rule: 'Regla',
    llm: 'IA',
    manual: 'Manual',
};

function statusOf(status: string): StatusMeta {
    return STATUS[status] ?? {
        label: status,
        color: 'neutral',
        hint: 'Estado de clasificación no reconocido.',
    };
}

/** `es-EC` formatea con `US$`; la bandeja muestra importes como `$115.00`. */
function money(cents: number): string {
    return `$${(cents / 100).toFixed(2)}`;
}

function confidence(is: number | null): string | null {
    return is === null ? null : `${Math.round(is * 100)} %`;
}

const summary = computed(() => {
    const counts: Record<string, number> = { auto: 0, review: 0, pending: 0, manual: 0 };

    for (const item of props.items) {
        const key = item.classification.status in counts ? item.classification.status : 'pending';
        counts[key] = (counts[key] ?? 0) + 1;
    }

    return counts;
});

const hasItems = computed(() => props.items.length > 0);
</script>

<template>
  <section class="rounded-lg border border-default">
    <header class="flex flex-wrap items-center justify-between gap-2 border-b border-default p-3">
      <div>
        <h3 class="text-xs font-medium text-highlighted">
          Líneas y clasificación
        </h3>
        <p class="mt-0.5 text-2xs text-muted">
          Categoría de gasto asignada a cada línea del comprobante.
        </p>
      </div>
      <div
        v-if="hasItems && !loading"
        class="flex flex-wrap items-center gap-1.5"
      >
        <UBadge
          v-for="key in (['auto', 'review', 'pending', 'manual'] as const)"
          :key="key"
          :color="statusOf(key).color"
          variant="subtle"
          size="sm"
          class="gap-1 text-2xs"
        >
          {{ statusOf(key).label }}: {{ summary[key] ?? 0 }}
        </UBadge>
      </div>
    </header>

    <div
      v-if="loading"
      class="space-y-2 p-3"
    >
      <div
        v-for="index in 3"
        :key="index"
        class="h-8 animate-pulse rounded bg-elevated"
      />
    </div>

    <p
      v-else-if="!hasItems"
      class="px-3 py-6 text-center text-xs text-muted"
    >
      Este comprobante no trae líneas de detalle.
    </p>

    <ul
      v-else
      class="divide-y divide-default"
    >
      <li
        v-for="item in items"
        :key="item.line_number"
        class="px-3 py-2.5"
      >
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="text-xs font-medium text-highlighted">
              <span class="mr-1.5 font-mono text-2xs text-dimmed">{{ item.line_number }}</span>
              {{ item.description }}
            </p>
            <p class="mt-0.5 font-mono text-2xs text-dimmed">
              {{ item.quantity }} × {{ money(Number(item.unit_price)) }}
              <span v-if="item.main_code"> · {{ item.main_code }}</span>
            </p>
          </div>
          <span class="shrink-0 font-mono text-xs tabular-nums text-highlighted">
            {{ money(Number(item.total_cents)) }}
          </span>
        </div>

        <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
          <UBadge
            :color="statusOf(item.classification.status).color"
            variant="subtle"
            size="sm"
            class="text-2xs"
            :title="statusOf(item.classification.status).hint"
          >
            {{ statusOf(item.classification.status).label }}
          </UBadge>

          <span
            v-if="item.classification.expense_category"
            class="rounded-md border border-default bg-elevated px-1.5 py-0.5 text-2xs text-highlighted"
          >
            {{ item.classification.expense_category.name }}
          </span>

          <span
            v-if="item.classification.method"
            class="text-2xs text-muted"
          >
            {{ METHOD_LABELS[item.classification.method] ?? item.classification.method }}
            <template v-if="confidence(item.classification.confidence)">
              · {{ confidence(item.classification.confidence) }}
            </template>
          </span>

          <RouterLink
            v-if="item.classification.status === 'review' || item.classification.status === 'pending'"
            :to="{ name: 'classifications' }"
            class="ml-auto text-2xs font-medium text-primary underline-offset-4 hover:underline"
          >
            Revisar
          </RouterLink>
        </div>
      </li>
    </ul>
  </section>
</template>
