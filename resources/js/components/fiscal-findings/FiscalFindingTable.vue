<script setup lang="ts">
import type { FiscalFinding } from '@/stores/fiscalFindings';

import {
    findingCodeLabel,
    formatDate,
    formatMoney,
    severityColor,
    severityLabel,
    statusColor,
    statusLabel,
} from '@/lib/fiscalFindings';

defineProps<{
    items: FiscalFinding[];
    actionsEnabled: boolean;
}>();

const emit = defineEmits<{
    view: [item: FiscalFinding];
    manage: [item: FiscalFinding];
}>();
</script>

<template>
  <section class="overflow-x-auto rounded-lg border border-default">
    <table class="w-full text-sm">
      <thead class="bg-elevated/60 text-left text-xs uppercase text-muted">
        <tr>
          <th class="px-3 py-2">
            Severidad
          </th>
          <th class="px-3 py-2">
            Hallazgo
          </th>
          <th class="px-3 py-2">
            Comprobante
          </th>
          <th class="px-3 py-2">
            Estado
          </th>
          <th class="px-3 py-2 text-center">
            Acciones
          </th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="item in items"
          :key="item.id"
          class="border-t border-default transition-colors hover:bg-elevated/40"
        >
          <td class="px-3 py-3 align-top">
            <UBadge
              :color="severityColor(item.severity)"
              variant="soft"
              size="md"
              :label="severityLabel(item.severity)"
            />
          </td>
          <td class="px-3 py-3 align-top">
            <div class="font-medium text-highlighted">
              {{ item.title }}
            </div>
            <div class="text-xs text-muted">
              {{ findingCodeLabel(item.code) }}
              <span v-if="item.company"> · {{ item.company.legal_name }}</span>
            </div>
            <div class="mt-1 line-clamp-2 text-xs text-dimmed">
              {{ item.description }}
            </div>
          </td>
          <td class="px-3 py-3 align-top">
            <template v-if="item.source">
              <div class="text-highlighted">
                {{ item.source.issuer_name }}
              </div>
              <div class="font-mono text-2xs text-muted">
                {{ item.source.issuer_ruc }} · {{ formatDate(item.source.issue_date) }}
              </div>
              <div class="font-mono text-2xs text-muted">
                {{ formatMoney(item.source.total_cents) }}
              </div>
            </template>
            <span
              v-else
              class="text-xs text-muted"
            >Sin comprobante asociado</span>
          </td>
          <td class="px-3 py-3 align-top">
            <UBadge
              :color="statusColor(item.status)"
              variant="soft"
              size="md"
              :label="statusLabel(item.status)"
            />
          </td>
          <td class="px-3 py-3 align-top text-right">
            <div class="flex justify-end gap-1">
              <UButton
                color="neutral"
                variant="ghost"
                size="xs"
                icon="i-lucide-eye"
                label="Ver"
                @click="emit('view', item)"
              />
              <UButton
                v-if="actionsEnabled && item.status !== 'resolved' && item.status !== 'dismissed'"
                color="primary"
                variant="ghost"
                size="xs"
                icon="i-lucide-check-check"
                label="Gestionar"
                @click="emit('manage', item)"
              />
            </div>
          </td>
        </tr>
      </tbody>
    </table>
  </section>
</template>
