<script setup lang="ts">
import type { ClassificationApi } from '@/stores/classifications';

defineProps<{
    item: ClassificationApi;
}>();

const emit = defineEmits<{
    accept: [id: number, categoryId: number | undefined];
    requestReject: [id: number];
    revert: [item: ClassificationApi];
    view: [item: ClassificationApi];
    createRule: [item: ClassificationApi];
}>();
</script>

<template>
  <div class="flex justify-end gap-1">
    <UButton
      v-if="item.status !== 'accepted'"
      color="success"
      variant="soft"
      size="xs"
      icon="i-lucide-check"
      title="Aceptar"
      aria-label="Aceptar"
      @click.stop="emit('accept', item.id, item.expense_category?.id)"
    />
    <UButton
      v-if="item.status !== 'rejected'"
      color="error"
      variant="soft"
      size="xs"
      icon="i-lucide-x"
      title="Rechazar"
      aria-label="Rechazar"
      @click.stop="emit('requestReject', item.id)"
    />
    <UButton
      v-if="item.status === 'accepted' || item.status === 'rejected'"
      color="neutral"
      variant="outline"
      size="xs"
      icon="i-lucide-rotate-ccw"
      title="Revertir"
      aria-label="Revertir"
      @click.stop="emit('revert', item)"
    />
    <UButton
      color="neutral"
      variant="ghost"
      size="xs"
      icon="i-lucide-eye"
      title="Ver comprobante"
      aria-label="Ver comprobante"
      @click.stop="emit('view', item)"
    />
    <UButton
      color="neutral"
      variant="ghost"
      size="xs"
      icon="i-lucide-wand-2"
      title="Crear regla"
      aria-label="Crear regla"
      @click.stop="emit('createRule', item)"
    />
  </div>
</template>