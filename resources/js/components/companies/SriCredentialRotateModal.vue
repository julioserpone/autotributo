<script setup lang="ts">
import { computed, ref, watch } from 'vue';

import type { Company } from '@/stores/company';

import { useSriCredentialStore } from '@/stores/sriCredential';

const props = defineProps<{
    open: boolean;
    company: Company | null;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'rotated'): void;
}>();

const store = useSriCredentialStore();

const password = ref('');
const confirmation = ref('');
const fieldErrors = ref<Record<string, string>>({});

const canSubmit = computed(() => password.value.length >= 6 && password.value === confirmation.value);

watch(() => props.open, (value) => {
    if (value) {
        password.value = '';
        confirmation.value = '';
        fieldErrors.value = {};
        store.error = null;
    }
});

async function submit(): Promise<void> {
    if (!props.company || !canSubmit.value) {
        return;
    }

    fieldErrors.value = {};

    try {
        await store.rotateCredential(props.company.id, password.value, confirmation.value);
        emit('rotated');
        emit('update:open', false);
    } catch (caught: unknown) {
        const problem = (caught as { problem?: { errors?: Record<string, string[]> } }).problem;

        if (problem?.errors) {
            for (const [field, messages] of Object.entries(problem.errors)) {
                fieldErrors.value[field] = messages[0] ?? '';
            }
        }
    }
}
</script>

<template>
  <UModal
    :open="open"
    title="Rotar contraseña del portal"
    description="La nueva contraseña reemplaza a la anterior. El usuario (RUC) no cambia."
    @update:open="emit('update:open', $event)"
  >
    <template #body>
      <div class="space-y-4">
        <UFormField
          label="Nueva contraseña"
          name="password"
          :error="fieldErrors.password ?? false"
        >
          <UInput
            v-model="password"
            type="password"
            color="neutral"
            variant="outline"
            class="w-full"
            autocomplete="new-password"
          />
        </UFormField>

        <UFormField
          label="Confirmar contraseña"
          name="password_confirmation"
          :error="fieldErrors.password_confirmation ?? false"
        >
          <UInput
            v-model="confirmation"
            type="password"
            color="neutral"
            variant="outline"
            class="w-full"
            autocomplete="new-password"
          />
        </UFormField>

        <UAlert
          v-if="store.error && Object.keys(fieldErrors).length === 0"
          color="error"
          variant="soft"
          icon="i-lucide-alert-circle"
          :description="store.error"
        />
      </div>
    </template>

    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          label="Cancelar"
          @click="emit('update:open', false)"
        />
        <UButton
          color="primary"
          size="sm"
          label="Rotar contraseña"
          :loading="store.saving"
          :disabled="!canSubmit || store.saving"
          @click="submit"
        />
      </div>
    </template>
  </UModal>
</template>