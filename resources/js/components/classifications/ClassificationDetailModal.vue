<script setup lang="ts">
import type { ClassificationApi } from '@/stores/classifications';

import { computed } from 'vue';

const props = defineProps<{
    open: boolean;
    item: ClassificationApi | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const openModel = computed({
    get: () => props.open,
    set: (value: boolean) => emit('update:open', value),
});

function money(cents: number): string {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(cents / 100);
}

const methodLabels: Record<string, string> = {
    rule: 'Regla',
    cache: 'Caché',
    llm: 'IA',
    manual: 'Manual',
};
</script>

<template>
  <UModal
    v-model:open="openModel"
    title="Detalle del comprobante"
    description="Información del comprobante para apoyar la decisión de clasificación."
  >
    <template #body>
      <div
        v-if="item"
        class="space-y-2 text-sm"
      >
        <div class="flex justify-between gap-4">
          <span class="text-muted">Emisor</span>
          <span class="font-medium text-highlighted">{{ item.fiscal_document?.issuer_name ?? '—' }}</span>
        </div>
        <div class="flex justify-between gap-4">
          <span class="text-muted">RUC emisor</span>
          <span class="font-mono text-highlighted">{{ item.fiscal_document?.issuer_ruc ?? '—' }}</span>
        </div>
        <div class="flex justify-between gap-4">
          <span class="text-muted">Fecha de emisión</span>
          <span class="text-highlighted">{{ item.fiscal_document?.issue_date ?? '—' }}</span>
        </div>
        <div class="flex justify-between gap-4">
          <span class="text-muted">Total comprobante</span>
          <span class="font-medium text-highlighted">{{ money(item.fiscal_document?.total_cents ?? 0) }}</span>
        </div>
        <div class="flex justify-between gap-4">
          <span class="text-muted">Ítem</span>
          <span class="text-highlighted">{{ item.item?.description ?? '—' }}</span>
        </div>
        <div class="flex justify-between gap-4">
          <span class="text-muted">Importe del ítem</span>
          <span class="font-medium text-highlighted">{{ money(item.item?.total_cents ?? 0) }}</span>
        </div>
        <div class="flex justify-between gap-4">
          <span class="text-muted">Categoría sugerida</span>
          <span class="text-highlighted">{{ item.expense_category?.name ?? 'Sin categoría' }}</span>
        </div>
        <div class="flex justify-between gap-4">
          <span class="text-muted">Confianza</span>
          <span class="text-highlighted">{{ Math.round(item.confidence * 100) }}% ({{ methodLabels[item.method] ?? item.method }})</span>
        </div>
      </div>
    </template>
    <template #footer>
      <UButton
        color="neutral"
        variant="outline"
        size="sm"
        label="Cerrar"
        @click="openModel = false"
      />
    </template>
  </UModal>
</template>