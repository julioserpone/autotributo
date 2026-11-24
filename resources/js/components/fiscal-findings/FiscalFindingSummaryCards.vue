<script setup lang="ts">
import type { FiscalFindingCounts } from '@/stores/fiscalFindings';

const props = defineProps<{
    counts: FiscalFindingCounts;
}>();

const cards = [
    {
        label: 'Abiertos',
        hint: 'Pendientes de gestión',
        icon: 'i-lucide-circle-alert',
        tone: 'text-red-500',
        value: () => props.counts.by_status.open ?? 0,
    },
    {
        label: 'Críticos',
        hint: 'Requieren revisión inmediata',
        icon: 'i-lucide-shield-alert',
        tone: 'text-red-500',
        value: () => props.counts.by_severity.critical ?? 0,
    },
    {
        label: 'Advertencias',
        hint: 'Revisar antes de declarar',
        icon: 'i-lucide-triangle-alert',
        tone: 'text-amber-500',
        value: () => props.counts.by_severity.warning ?? 0,
    },
    {
        label: 'Resueltos',
        hint: 'Atendidos en este tenant',
        icon: 'i-lucide-circle-check',
        tone: 'text-emerald-500',
        value: () => props.counts.by_status.resolved ?? 0,
    },
];
</script>

<template>
  <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <article
      v-for="card in cards"
      :key="card.label"
      class="rounded-lg border border-default bg-elevated/50 p-4"
    >
      <div class="flex items-start justify-between">
        <div>
          <h2 class="text-xs font-medium text-muted">
            {{ card.label }}
          </h2>
          <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-highlighted">
            {{ card.value() }}
          </p>
        </div>
        <UIcon
          :name="card.icon"
          class="size-5"
          :class="card.tone"
        />
      </div>
      <p class="mt-2 text-2xs text-dimmed">
        {{ card.hint }}
      </p>
    </article>
  </section>
</template>
