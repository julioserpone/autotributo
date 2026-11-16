<script setup lang="ts">
import { computed } from 'vue';

import AppBadge from '@/components/ui/AppBadge.vue';
import type { Tone } from '@/components/ui/types';
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

const tones: Record<HealthStatus, Tone> = {
    idle: 'neutral',
    loading: 'warning',
    ok: 'success',
    error: 'danger',
};

const label = computed(() => labels[props.status]);
const tone = computed(() => tones[props.status]);
</script>

<template>
  <AppBadge
    :tone="tone"
    :data-status="status"
    dot
  >
    {{ label }}
  </AppBadge>
</template>
