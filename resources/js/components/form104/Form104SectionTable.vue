<script setup lang="ts">
import type { Form104SectionGroup } from '@/lib/form104';
import type { Form104Line } from '@/stores/form104';

import { formatCents } from '@/lib/dashboard';
import {
  form104DrilldownRoute,
  form104StateColor,
  form104StateLabel,
  form104Value,
} from '@/lib/form104';

/**
 * Sección del formulario 104 con sus casilleros (F4-09).
 *
 * Cada fila muestra el campo oficial, su descripción, el valor y el estado.
 * Los casilleros con fuente de comprobantes ofrecen drill-down a la bandeja;
 * los pendientes o manuales explican por qué no hay cifra.
 */
defineProps<{
  section: Form104SectionGroup;
  loading?: boolean;
}>();

const hasValue = (line: Form104Line): boolean =>
  line.value_cents !== null || line.rate !== null;

const showValue = (line: Form104Line): string =>
  hasValue(line) || line.unavailable || line.manual ? form104Value(line, formatCents) : '—';
</script>

<template>
  <section class="rounded-lg border border-default bg-elevated/40">
    <header class="flex flex-wrap items-baseline justify-between gap-2 border-b border-default px-4 py-3">
      <div>
        <h2 class="text-sm font-semibold text-highlighted">
          {{ section.title }}
        </h2>
        <p class="mt-0.5 text-2xs text-muted">
          {{ section.computed }} casilleros calculados · {{ section.pending }} pendientes
        </p>
      </div>
      <span class="font-mono text-2xs text-dimmed">
        Casilleros {{ section.lines[0]?.field }}–{{ section.lines[section.lines.length - 1]?.field }}
      </span>
    </header>

    <div
      v-if="loading"
      class="space-y-2 p-4"
      role="status"
      aria-live="polite"
    >
      <div
        v-for="index in 4"
        :key="index"
        class="h-7 animate-pulse rounded bg-default"
      />
      <span class="sr-only">Cargando casilleros…</span>
    </div>

    <ul
      v-else
      class="divide-y divide-default"
    >
      <li
        v-for="line in section.lines"
        :key="line.field"
        class="grid grid-cols-[4.5rem_minmax(0,1fr)_auto] items-start gap-3 px-4 py-2.5"
        :class="line.unavailable || line.manual ? 'opacity-80' : ''"
      >
        <span class="font-mono text-xs font-semibold tabular-nums text-highlighted">
          {{ line.field }}
        </span>

        <div class="min-w-0">
          <p
            class="text-xs text-highlighted"
            :class="line.unavailable || line.manual ? 'text-muted' : ''"
          >
            {{ line.label }}
          </p>
          <p
            v-if="line.note"
            class="mt-0.5 text-2xs text-dimmed"
          >
            {{ line.note }}
          </p>
          <div class="mt-1 flex flex-wrap items-center gap-1.5">
            <UBadge
              :color="form104StateColor(line)"
              variant="subtle"
              size="sm"
              class="text-2xs"
            >
              {{ form104StateLabel(line) }}
            </UBadge>
            <UBadge
              v-if="line.requires_signoff && !line.unavailable && !line.manual"
              color="warning"
              variant="outline"
              size="sm"
              class="text-2xs"
            >
              Firma del contador
            </UBadge>
            <RouterLink
              v-if="form104DrilldownRoute(line)"
              :to="form104DrilldownRoute(line) ?? ''"
              class="text-2xs font-medium text-primary underline-offset-4 hover:underline"
            >
              Ver comprobantes
            </RouterLink>
          </div>
        </div>

        <span
          class="shrink-0 font-mono text-sm tabular-nums"
          :class="line.unavailable || line.manual ? 'text-dimmed' : 'text-highlighted'"
        >
          {{ showValue(line) }}
        </span>
      </li>
    </ul>
  </section>
</template>
