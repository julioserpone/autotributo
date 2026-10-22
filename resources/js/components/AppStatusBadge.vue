<script setup lang="ts">
import { computed } from 'vue';

import type { HealthStatus } from '@/stores/health';

const props = defineProps<{
    status: HealthStatus;
}>();

const labels: Record<HealthStatus, string> = {
    idle: 'Sin verificar',
    loading: 'Verificando…',
    ok: 'API conectada',
    error: 'API no disponible',
};

const classes: Record<HealthStatus, string> = {
    idle: 'border-zinc-700 bg-zinc-800 text-zinc-300',
    loading: 'border-amber-700 bg-amber-950 text-amber-300',
    ok: 'border-emerald-700 bg-emerald-950 text-emerald-300',
    error: 'border-red-700 bg-red-950 text-red-300',
};

const label = computed(() => labels[props.status]);
const tone = computed(() => classes[props.status]);
</script>

<template>
  <span
    class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-medium"
    :class="tone"
    :data-status="status"
  >
    <span
      class="size-1.5 rounded-full bg-current"
      aria-hidden="true"
    />
    {{ label }}
  </span>
</template>
