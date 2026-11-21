<script setup lang="ts">
import type { ClassificationApi } from '@/stores/classifications';

import { confidenceColor, methodLabels, statusLabels } from '@/stores/classifications';

defineProps<{
    items: ClassificationApi[];
    focusIndex: number;
    selectedIds: Set<number>;
}>();

const emit = defineEmits<{
    'update:focusIndex': [value: number];
    toggleSelection: [id: number];
    accept: [id: number, categoryId: number | undefined];
    requestReject: [id: number];
    view: [item: ClassificationApi];
    createRule: [item: ClassificationApi];
}>();

function money(cents: number): string {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(cents / 100);
}

function statusColor(status: string): 'success' | 'warning' | 'error' | 'neutral' | 'info' {
    switch (status) {
        case 'accepted':
        case 'auto':
        case 'manual':
            return 'success';
        case 'suggested':
            return 'info';
        case 'needs_review':
            return 'warning';
        case 'rejected':
            return 'error';
        default:
            return 'neutral';
    }
}
</script>

<template>
  <section class="overflow-hidden rounded-lg border border-default">
    <table class="w-full text-sm">
      <thead class="bg-elevated/60 text-left text-xs uppercase text-muted">
        <tr>
          <th class="w-10 px-3 py-2" />
          <th class="px-3 py-2">
            Ítem / comprobante
          </th>
          <th class="px-3 py-2">
            Estado
          </th>
          <th class="px-3 py-2">
            Categoría sugerida
          </th>
          <th class="px-3 py-2">
            Confianza
          </th>
          <th class="px-3 py-2">
            Método
          </th>
          <th class="px-3 py-2 text-right">
            Importe
          </th>
          <th class="px-3 py-2 text-right">
            Acciones
          </th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="(item, index) in items"
          :key="item.id"
          :class="['border-t border-default transition-colors', index === focusIndex ? 'bg-primary/10' : 'hover:bg-elevated/40']"
          @click="emit('update:focusIndex', index)"
        >
          <td class="px-3 py-2">
            <input
              type="checkbox"
              :checked="selectedIds.has(item.id)"
              class="size-4 rounded border-default"
              @change="emit('toggleSelection', item.id)"
            >
          </td>
          <td class="px-3 py-2">
            <div class="font-medium text-highlighted">
              {{ item.item?.description ?? '—' }}
            </div>
            <div class="text-xs text-muted">
              {{ item.fiscal_document?.issuer_name ?? '—' }} · {{ item.fiscal_document?.issue_date ?? '—' }}
            </div>
          </td>
          <td class="px-3 py-2">
            <UBadge
              :color="statusColor(item.status)"
              variant="soft"
              size="sm"
              :label="statusLabels[item.status] ?? item.status"
            />
          </td>
          <td class="px-3 py-2">
            <span v-if="item.expense_category">{{ item.expense_category.name }}</span>
            <span
              v-else
              class="text-muted"
            >Sin categoría</span>
          </td>
          <td class="px-3 py-2">
            <UBadge
              :color="confidenceColor(item.confidence)"
              variant="soft"
              size="sm"
              :label="`${Math.round(item.confidence * 100)}%`"
            />
          </td>
          <td class="px-3 py-2 text-muted">
            {{ methodLabels[item.method] ?? item.method }}
          </td>
          <td class="px-3 py-2 text-right font-medium">
            {{ money(item.item?.total_cents ?? 0) }}
          </td>
          <td class="px-3 py-2 text-right">
            <div class="flex justify-end gap-1">
              <UButton
                color="success"
                variant="soft"
                size="xs"
                icon="i-lucide-check"
                title="Aceptar"
                aria-label="Aceptar"
                @click.stop="emit('accept', item.id, item.expense_category?.id)"
              />
              <UButton
                color="error"
                variant="soft"
                size="xs"
                icon="i-lucide-x"
                title="Rechazar"
                aria-label="Rechazar"
                @click.stop="emit('requestReject', item.id)"
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
          </td>
        </tr>
      </tbody>
    </table>
  </section>
</template>