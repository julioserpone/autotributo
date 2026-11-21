<script setup lang="ts">
import { computed, ref, watch } from 'vue';

import type { Company } from '@/stores/company';

import { useCompanyStore } from '@/stores/company';

const props = defineProps<{
    open: boolean;
    company: Company | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    archived: [];
}>();

const companyStore = useCompanyStore();
const toast = useToast(); // eslint-disable-line no-undef

const openModel = ref(props.open);
const confirmText = ref('');
const submitting = ref(false);
const error = ref<string | null>(null);

const canConfirm = computed(() => {
    if (!props.company) return false;
    return confirmText.value.trim() === props.company.ruc;
});

watch(() => props.open, (value) => {
    openModel.value = value;

    if (value) {
        confirmText.value = '';
        error.value = null;
    }
});

watch(openModel, (value) => emit('update:open', value));

async function submit(): Promise<void> {
    if (!props.company || !canConfirm.value) return;

    submitting.value = true;
    error.value = null;

    try {
        await companyStore.archiveCompany(props.company.id);
        toast.add({ title: 'Contribuyente archivado', color: 'success' });
        emit('archived');
        openModel.value = false;
    } catch (caught: unknown) {
        error.value = caught instanceof Error ? caught.message : 'No se pudo archivar el contribuyente.';
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
  <UModal
    v-model:open="openModel"
    title="Archivar contribuyente"
    description="La empresa deja de operar ante el SRI y se borra su credencial cifrada. El historial de comprobantes y declaraciones se conserva."
  >
    <template #body>
      <div class="space-y-3">
        <div
          v-if="error"
          role="alert"
          class="rounded-md border border-error/40 bg-error/10 px-3 py-2 text-xs text-error"
        >
          {{ error }}
        </div>

        <p class="text-sm text-muted">
          Para confirmar, escribe el RUC <span class="font-mono font-medium text-highlighted">{{ company?.ruc }}</span>.
        </p>

        <UInput
          v-model="confirmText"
          color="neutral"
          variant="soft"
          size="md"
          class="w-full"
          placeholder="Escribe el RUC"
        />

        <div class="flex items-center justify-end gap-2 pt-2">
          <UButton
            color="neutral"
            variant="ghost"
            :disabled="submitting"
            @click="openModel = false"
          >
            Cancelar
          </UButton>
          <UButton
            color="error"
            :loading="submitting"
            :disabled="!canConfirm || submitting"
            @click="submit"
          >
            Archivar
          </UButton>
        </div>
      </div>
    </template>
  </UModal>
</template>
