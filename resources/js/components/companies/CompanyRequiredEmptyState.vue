<template>
  <div class="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-default bg-elevated/40 px-6 py-12 text-center">
    <UIcon
      :name="icon"
      class="size-10 text-dimmed"
    />
    <div>
      <p class="text-base font-medium text-highlighted">
        {{ title }}
      </p>
      <p class="mt-1 text-sm text-muted">
        {{ description }}
      </p>
    </div>
    <UButton
      color="primary"
      icon="i-lucide-plus"
      :label="actionLabel"
      :to="to"
    />
  </div>
</template>

<script setup lang="ts">
withDefaults(defineProps<{
    title?: string;
    description?: string;
    actionLabel?: string;
    to?: string;
    icon?: string;
}>(), {
    title: 'Registra tu primer contribuyente',
    description: 'Sin empresas no hay comprobantes que sincronizar ni clasificar.',
    actionLabel: 'Registrar contribuyente',
    to: '/companies?onboarding=1',
    icon: 'i-lucide-building-2',
});
</script>