<script setup lang="ts">
defineProps<{
    count: number;
    categories: { label: string; value: number }[];
    category: number;
}>();

const emit = defineEmits<{
    'update:category': [value: number];
    accept: [];
    reject: [];
    clear: [];
}>();
</script>

<template>
  <section class="sticky top-0 z-10 flex flex-wrap items-center gap-3 rounded-lg border border-primary/40 bg-elevated p-3">
    <span class="text-sm font-medium">{{ count }} seleccionados</span>
    <USelect
      :model-value="category"
      :items="categories"
      placeholder="Categoría…"
      color="neutral"
      variant="outline"
      size="sm"
      class="w-48"
      @update:model-value="(value: number) => emit('update:category', value)"
    />
    <UButton
      color="primary"
      variant="solid"
      size="sm"
      label="Aceptar sugeridas"
      icon="i-lucide-check"
      :disabled="category === 0"
      @click="emit('accept')"
    />
    <UButton
      color="error"
      variant="soft"
      size="sm"
      label="Rechazar"
      icon="i-lucide-x"
      @click="emit('reject')"
    />
    <UButton
      color="neutral"
      variant="ghost"
      size="sm"
      label="Limpiar"
      @click="emit('clear')"
    />
  </section>
</template>