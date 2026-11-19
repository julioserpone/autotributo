<script setup lang="ts">
import { computed } from 'vue';

import type { HealthStatus } from '@/stores/health';

const props = defineProps<{
    status: HealthStatus;
}>();

const config = computed(() => {
    switch (props.status) {
        case 'ok':
            return { label: 'API conectada', color: 'success' as const };
        case 'loading':
            return { label: 'Verificando…', color: 'warning' as const };
        case 'error':
            return { label: 'API no disponible', color: 'error' as const };
        default:
            return { label: 'Sin verificar', color: 'neutral' as const };
    }
});
</script>

<template>
  <UBadge
    :color="config.color"
    variant="subtle"
    size="sm"
    class="gap-1.5 font-medium h-8"
    :data-status="status"
  >
    <span
      v-if="status === 'ok'"
      class="relative flex size-2"
    >
      <span class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75" />
      <span class="relative inline-flex size-2 rounded-full bg-emerald-500" />
    </span>
    {{ config.label }}
  </UBadge>
</template>
