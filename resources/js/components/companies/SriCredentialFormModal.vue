<script setup lang="ts">
import { computed, ref, watch } from 'vue';

import type { Company } from '@/stores/company';

import { useSriCredentialStore } from '@/stores/sriCredential';
import PasswordRevealButton from '@/components/ui/PasswordRevealButton.vue';

const props = defineProps<{
    open: boolean;
    company: Company | null;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'saved', credential: unknown): void;
}>();

const store = useSriCredentialStore();

const password = ref('');
const confirmation = ref('');
const fieldErrors = ref<Record<string, string>>({});
const revealPassword = ref(false);
const revealConfirmation = ref(false);

const username = computed(() => props.company?.ruc ?? '');

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
        const credential = await store.storeCredential(props.company.id, {
            username: username.value,
            password: password.value,
            password_confirmation: confirmation.value,
        });

        emit('saved', credential);
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
    title="Credencial del portal SRI"
    description="Usuario y contraseña con los que el robot descarga los comprobantes de este contribuyente."
    @update:open="emit('update:open', $event)"
  >
    <template #body>
      <div class="space-y-4">
        <UFormField
          label="Usuario (RUC)"
          name="username"
        >
          <UInput
            :model-value="username"
            readonly
            color="neutral"
            variant="outline"
            class="w-full"
          />
        </UFormField>

        <UFormField
          label="Contraseña"
          name="password"
          :error="fieldErrors.password ?? false"
        >
          <UInput
            v-model="password"
            :type="revealPassword ? 'text' : 'password'"
            color="neutral"
            variant="outline"
            class="w-full"
            autocomplete="new-password"
          >
            <template #trailing>
              <PasswordRevealButton
                :revealed="revealPassword"
                @toggle="revealPassword = !revealPassword"
              />
            </template>
          </UInput>
        </UFormField>

        <UFormField
          label="Confirmar contraseña"
          name="password_confirmation"
          :error="fieldErrors.password_confirmation ?? false"
        >
          <UInput
            v-model="confirmation"
            :type="revealConfirmation ? 'text' : 'password'"
            color="neutral"
            variant="outline"
            class="w-full"
            autocomplete="new-password"
          >
            <template #trailing>
              <PasswordRevealButton
                :revealed="revealConfirmation"
                @toggle="revealConfirmation = !revealConfirmation"
              />
            </template>
          </UInput>
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
          label="Guardar credencial"
          :loading="store.saving"
          :disabled="!canSubmit || store.saving"
          @click="submit"
        />
      </div>
    </template>
  </UModal>
</template>