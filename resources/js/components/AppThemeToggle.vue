<script setup lang="ts">
import { computed } from 'vue';

import { useTheme } from '@/composables/useTheme';

const { isDark, toggleTheme } = useTheme();

const label = computed(() => (isDark.value ? 'Modo claro' : 'Modo oscuro'));
const icon = computed(() => (isDark.value ? 'i-lucide-sun' : 'i-lucide-moon'));
</script>

<template>
  <UButton
    color="neutral"
    variant="outline"
    size="sm"
    :icon="icon"
    :aria-label="label"
    class="gap-2"
    @click="toggleTheme"
  >
    <span class="hidden sm:inline">{{ label }}</span>
  </UButton>
</template>
