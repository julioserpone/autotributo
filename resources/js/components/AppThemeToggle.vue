<script setup lang="ts">
import { computed } from 'vue';

import { useTheme } from '@/composables/useTheme';

const { theme, toggleTheme } = useTheme();

const label = computed(() => {
    switch (theme.value) {
        case 'light':
            return 'Modo claro';
        case 'dark':
            return 'Modo oscuro';
        default:
            return 'Modo sistema';
    }
});

const icon = computed(() => {
    switch (theme.value) {
        case 'light':
            return 'i-lucide-sun';
        case 'dark':
            return 'i-lucide-moon';
        default:
            return 'i-lucide-monitor';
    }
});
</script>

<template>
  <UButton
    color="neutral"
    variant="outline"
    size="md"
    :icon="icon"
    :aria-label="label"
    class="gap-2"
    @click="toggleTheme"
  >
    <span class="hidden sm:inline">{{ label }}</span>
  </UButton>
</template>
