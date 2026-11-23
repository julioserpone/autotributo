<script setup lang="ts">
import type { FiscalFinding } from '@/stores/fiscalFindings';

import { computed } from 'vue';

import {
    findingCodeHint,
    formatDate,
    formatMoney,
    severityColor,
    severityLabel,
    statusColor,
    statusLabel,
} from '@/lib/fiscalFindings';

const props = defineProps<{
    open: boolean;
    finding: FiscalFinding | null;
    actionsEnabled: boolean;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    manage: [item: FiscalFinding];
}>();

const openModel = computed({
    get: () => props.open,
    set: (value: boolean) => emit('update:open', value),
});

const metadataEntries = computed(() => {
    const metadata = props.finding?.metadata ?? {};

    return Object.entries(metadata)
        .filter(([key]) => key !== 'note')
        .map(([key, value]) => ({
            key,
            value: typeof value === 'object' && value !== null ? JSON.stringify(value) : String(value),
        }));
});
</script>

<template>
  <USlideover
    v-model:open="openModel"
    side="right"
    :ui="{ content: 'max-w-xl z-30', header: 'border-b-0' }"
    title="Detalle del hallazgo"
  >
    <template #body>
      <div
        v-if="finding"
        class="space-y-4 text-sm"
      >
        <div class="flex flex-wrap items-center gap-2">
          <UBadge
            :color="severityColor(finding.severity)"
            variant="soft"
            size="sm"
            :label="severityLabel(finding.severity)"
          />
          <UBadge
            :color="statusColor(finding.status)"
            variant="soft"
            size="sm"
            :label="statusLabel(finding.status)"
          />
          <span class="font-mono text-2xs text-muted">{{ finding.code }}</span>
        </div>

        <div>
          <h3 class="text-base font-semibold text-highlighted">
            {{ finding.title }}
          </h3>
          <p class="mt-1 text-sm text-muted">
            {{ finding.description }}
          </p>
          <p
            v-if="findingCodeHint(finding.code)"
            class="mt-2 rounded-lg border border-default bg-muted/50 p-2 text-xs text-muted"
          >
            {{ findingCodeHint(finding.code) }}
          </p>
        </div>

        <dl class="grid grid-cols-2 gap-3">
          <div>
            <dt class="text-2xs uppercase tracking-wider text-dimmed">
              Contribuyente
            </dt>
            <dd class="text-highlighted">
              {{ finding.company?.legal_name ?? '—' }}
            </dd>
            <dd class="font-mono text-2xs text-muted">
              {{ finding.company?.ruc ?? '' }}
            </dd>
          </div>
          <div>
            <dt class="text-2xs uppercase tracking-wider text-dimmed">
              Detectado
            </dt>
            <dd class="text-highlighted">
              {{ formatDate(finding.created_at) }}
            </dd>
          </div>
        </dl>

        <div
          v-if="finding.source"
          class="rounded-lg border border-default p-3"
        >
          <div class="mb-2 flex items-center justify-between">
            <span class="text-2xs uppercase tracking-wider text-dimmed">Comprobante de origen</span>
            <UButton
              color="neutral"
              variant="ghost"
              size="xs"
              icon="i-lucide-external-link"
              label="Abrir bandeja"
              :to="{ name: 'fiscal_documents', query: { issuer: finding.source.issuer_ruc } }"
            />
          </div>
          <div class="space-y-1 text-xs">
            <div class="flex justify-between gap-3">
              <span class="text-muted">Emisor</span>
              <span class="text-right text-highlighted">{{ finding.source.issuer_name }}</span>
            </div>
            <div class="flex justify-between gap-3">
              <span class="text-muted">RUC</span>
              <span class="font-mono text-highlighted">{{ finding.source.issuer_ruc }}</span>
            </div>
            <div class="flex justify-between gap-3">
              <span class="text-muted">Fecha de emisión</span>
              <span class="text-highlighted">{{ formatDate(finding.source.issue_date) }}</span>
            </div>
            <div class="flex justify-between gap-3">
              <span class="text-muted">Total</span>
              <span class="font-mono tabular-nums text-highlighted">{{ formatMoney(finding.source.total_cents) }}</span>
            </div>
          </div>
        </div>

        <div
          v-if="finding.note"
          class="rounded-lg border border-default bg-muted/50 p-3 text-xs"
        >
          <div class="text-2xs uppercase tracking-wider text-dimmed">
            Nota de gestión
          </div>
          <p class="mt-1 text-highlighted">
            {{ finding.note }}
          </p>
        </div>

        <div
          v-if="finding.resolved_by || finding.resolved_at"
          class="text-xs text-muted"
        >
          <span v-if="finding.resolved_by">Gestionado por {{ finding.resolved_by.name }}</span>
          <span v-if="finding.resolved_at"> · {{ formatDate(finding.resolved_at) }}</span>
        </div>

        <div v-if="metadataEntries.length > 0">
          <div class="text-2xs uppercase tracking-wider text-dimmed">
            Datos de la detección
          </div>
          <dl class="mt-1 space-y-1 text-xs">
            <div
              v-for="entry in metadataEntries"
              :key="entry.key"
              class="flex justify-between gap-3"
            >
              <dt class="text-muted">
                {{ entry.key }}
              </dt>
              <dd class="break-all text-right font-mono text-highlighted">
                {{ entry.value }}
              </dd>
            </div>
          </dl>
        </div>
      </div>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          label="Cerrar"
          @click="openModel = false"
        />
        <UButton
          v-if="finding && actionsEnabled && finding.status !== 'resolved' && finding.status !== 'dismissed'"
          color="primary"
          variant="solid"
          size="sm"
          icon="i-lucide-check-check"
          label="Gestionar"
          @click="emit('manage', finding)"
        />
      </div>
    </template>
  </USlideover>
</template>
