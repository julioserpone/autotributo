<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    open: boolean;
    title?: string;
    description?: string;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    confirm: [];
}>();

const openModel = computed({
    get: () => props.open,
    set: (value: boolean) => emit('update:open', value),
});
</script>

<template>
  <UModal
    v-model:open="openModel"
    :title="title ?? '¿Rechazar clasificación?'"
    :description="description ?? 'El ítem volverá a estado pendiente para que una regla posterior lo reintente.'"
  >
    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          label="Cancelar"
          @click="openModel = false"
        />
        <UButton
          color="error"
          variant="solid"
          size="sm"
          label="Rechazar"
          @click="emit('confirm')"
        />
      </div>
    </template>
  </UModal>
</template>