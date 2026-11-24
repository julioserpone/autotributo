<script setup lang="ts">
import type { FiscalFinding } from '@/stores/fiscalFindings';
import type { FindingAction } from '@/lib/fiscalFindings';

import { computed, ref, watch } from 'vue';

import { actionLabels } from '@/lib/fiscalFindings';

const props = defineProps<{
    open: boolean;
    finding: FiscalFinding | null;
    action: FindingAction;
    saving: boolean;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    confirm: [action: FindingAction, note: string];
}>();

const openModel = computed({
    get: () => props.open,
    set: (value: boolean) => emit('update:open', value),
});

const selectedAction = ref<FindingAction>(props.action);
const note = ref('');
const error = ref<string | null>(null);

const actionOptions = [
    { label: 'Reconocer', value: 'acknowledged', description: 'Queda visto por el equipo pero sigue pendiente de resolver.' },
    { label: 'Resolver', value: 'resolved', description: 'El hallazgo se corrigió o se validó contra el comprobante.' },
    { label: 'Descartar', value: 'dismissed', description: 'No aplica para este contribuyente. Exige un motivo.' },
];

const currentHint = computed(() => actionOptions.find((option) => option.value === selectedAction.value)?.description ?? '');

const requiresNote = computed(() => selectedAction.value === 'dismissed');
const canConfirm = computed(() => !requiresNote.value || note.value.trim().length > 0);

watch(() => props.open, (value) => {
    if (value) {
        selectedAction.value = props.action;
        note.value = props.finding?.note ?? '';
        error.value = null;
    }
});

function confirm(): void {
    if (!canConfirm.value) {
        error.value = 'Indica el motivo para descartar el hallazgo.';
        return;
    }

    emit('confirm', selectedAction.value, note.value);
}
</script>

<template>
  <UModal
    v-model:open="openModel"
    title="Gestionar hallazgo"
    :ui="{ overlay: 'z-[60]', content: 'z-[70]' }"
    :description="finding?.title ?? 'Define el estado del hallazgo y deja constancia del motivo.'"
  >
    <template #body>
      <div class="space-y-4 text-sm">
        <div class="space-y-2">
          <label
            v-for="option in actionOptions"
            :key="option.value"
            class="flex cursor-pointer items-start gap-3 rounded-lg border border-default p-3 transition-colors hover:bg-elevated/40"
            :class="{ 'border-primary bg-primary/5': selectedAction === option.value }"
          >
            <input
              v-model="selectedAction"
              type="radio"
              name="finding-action"
              :value="option.value"
              class="mt-1 size-4"
            >
            <span>
              <span class="block font-medium text-highlighted">{{ option.label }}</span>
              <span class="block text-xs text-muted">{{ option.description }}</span>
            </span>
          </label>
        </div>

        <UFormField
          :label="requiresNote ? 'Motivo (obligatorio)' : 'Nota (opcional)'"
          name="note"
        >
          <UTextarea
            v-model="note"
            :rows="3"
            :placeholder="requiresNote ? 'Explica por qué el hallazgo no aplica…' : 'Contexto para el resto del equipo…'"
            color="neutral"
            variant="outline"
            class="w-full"
            maxlength="500"
          />
        </UFormField>

        <p class="text-xs text-muted">
          {{ currentHint }}
        </p>

        <p
          v-if="error"
          class="text-xs text-error"
        >
          {{ error }}
        </p>
      </div>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          label="Cancelar"
          @click="openModel = false"
        />
        <UButton
          color="primary"
          variant="solid"
          size="sm"
          :loading="saving"
          :disabled="!canConfirm"
          :label="actionLabels[selectedAction]"
          @click="confirm"
        />
      </div>
    </template>
  </UModal>
</template>
