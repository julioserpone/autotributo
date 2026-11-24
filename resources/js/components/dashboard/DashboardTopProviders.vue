<script setup lang="ts">
import { computed } from 'vue';

import type { DashboardTopProvider } from '@/stores/dashboard';
import { formatCents, formatCount } from '@/lib/dashboard';

const props = defineProps<{
    providers: DashboardTopProvider[];
    loading?: boolean;
}>();

const maxTotal = computed(() => Math.max(...props.providers.map((provider) => provider.total_cents), 1));

function barWidth(cents: number): string {
    return `${Math.round((cents / maxTotal.value) * 100)}%`;
}
</script>

<template>
  <section class="overflow-hidden rounded-lg border border-default bg-elevated/50">
    <header class="border-b border-default p-4">
      <h2 class="text-sm font-medium text-highlighted">
        Top proveedores del período
      </h2>
      <p class="mt-0.5 text-xs text-muted">
        Cinco emisores con mayor valor facturado (excluye retenciones).
      </p>
    </header>

    <div
      v-if="loading"
      class="space-y-3 p-4"
    >
      <div
        v-for="index in 3"
        :key="index"
        class="h-10 animate-pulse rounded bg-elevated"
      />
    </div>

    <p
      v-else-if="providers.length === 0"
      class="px-4 py-10 text-center text-xs text-muted"
    >
      Aún no hay compras registradas en este período.
    </p>

    <ul
      v-else
      class="divide-y divide-default"
    >
      <li
        v-for="provider in providers"
        :key="provider.issuer_ruc"
        class="px-4 py-3"
      >
        <div class="flex items-baseline justify-between gap-3 text-xs">
          <div class="min-w-0">
            <p class="truncate font-medium text-highlighted">
              {{ provider.issuer_name }}
            </p>
            <p class="font-mono text-2xs text-dimmed">
              {{ provider.issuer_ruc }} · {{ formatCount(provider.documents_count, 'comprobante', 'comprobantes') }}
            </p>
          </div>
          <span class="shrink-0 font-mono font-semibold tabular-nums text-highlighted">
            {{ formatCents(provider.total_cents) }}
          </span>
        </div>
        <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-elevated">
          <div
            class="h-full rounded-full bg-primary transition-all"
            :style="{ width: barWidth(provider.total_cents) }"
          />
        </div>
      </li>
    </ul>
  </section>
</template>
