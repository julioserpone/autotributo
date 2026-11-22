<script setup lang="ts">
defineProps<{
    revealed: boolean;
}>();

const emit = defineEmits<{
    (e: 'toggle'): void;
}>();
</script>

<template>
  <UButton
    color="neutral"
    variant="ghost"
    size="xs"
    square
    :icon="revealed ? 'i-lucide-eye-off' : 'i-lucide-eye'"
    aria-label="Mostrar u ocultar contraseña"
    @click="emit('toggle')"
  />
</template>