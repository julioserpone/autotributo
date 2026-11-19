<script setup lang="ts">
import { computed } from 'vue';

import { useTheme } from '@/composables/useTheme';

const { theme, setTheme } = useTheme();

const triggerIcon = computed(() => {
    if (theme.value === 'light') return 'i-lucide-sun';
    if (theme.value === 'dark') return 'i-lucide-moon';
    return 'i-lucide-monitor';
});

const triggerLabel = computed(() => {
    if (theme.value === 'light') return 'Tema claro';
    if (theme.value === 'dark') return 'Tema oscuro';
    return 'Tema del sistema';
});

const items = computed(() => [
    [
        {
            label: 'Claro',
            icon: 'i-lucide-sun',
            type: 'checkbox' as const,
            checked: theme.value === 'light',
            onSelect: (event: Event) => {
                event.preventDefault();
                setTheme('light');
            },
        },
        {
            label: 'Oscuro',
            icon: 'i-lucide-moon',
            type: 'checkbox' as const,
            checked: theme.value === 'dark',
            onSelect: (event: Event) => {
                event.preventDefault();
                setTheme('dark');
            },
        },
        {
            label: 'Sistema',
            icon: 'i-lucide-monitor',
            type: 'checkbox' as const,
            checked: theme.value === 'system',
            onSelect: (event: Event) => {
                event.preventDefault();
                setTheme('system');
            },
        },
    ],
]);
</script>

<template>
  <UDropdownMenu
    :items="items"
    :content="{ align: 'end', collisionPadding: 12 }"
    :ui="{ content: 'w-44' }"
  >
    <UButton
      color="neutral"
      variant="ghost"
      size="sm"
      :icon="triggerIcon"
      square
      :aria-label="triggerLabel"
      class="h-8"
    />
  </UDropdownMenu>
</template>
