<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';

import AuthLayout from '@/layouts/AuthLayout.vue';
import { postJson } from '@/lib/api';

const router = useRouter();

const code = ref('');
const recoveryCode = ref('');
const useRecovery = ref(false);
const submitting = ref(false);
const error = ref<string | null>(null);

async function submit(): Promise<void> {
    if (submitting.value) {
        return;
    }

    submitting.value = true;
    error.value = null;

    try {
        await postJson('/api/v1/auth/two-factor/challenge', useRecovery.value
            ? { recovery_code: recoveryCode.value }
            : { code: code.value });
        await router.push({ name: 'dashboard' });
    } catch (caught: unknown) {
        error.value = caught instanceof Error ? caught.message : 'No se pudo verificar el código.';
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
  <AuthLayout
    title="Verificación en dos pasos"
    subtitle="Ingresa el código de tu aplicación de autenticación."
  >
    <form
      class="space-y-4"
      @submit.prevent="submit"
    >
      <template v-if="!useRecovery">
        <UFormField
          label="Código"
          name="code"
        >
          <UInput
            id="code"
            v-model="code"
            type="text"
            inputmode="numeric"
            autocomplete="one-time-code"
            required
            icon="i-lucide-key-round"
            size="sm"
          />
        </UFormField>
      </template>

      <template v-else>
        <UFormField
          label="Código de recuperación"
          name="recovery_code"
        >
          <UInput
            id="recovery_code"
            v-model="recoveryCode"
            type="text"
            required
            icon="i-lucide-key-round"
            size="sm"
          />
        </UFormField>
      </template>

      <UAlert
        v-if="error"
        color="error"
        variant="soft"
        icon="i-lucide-alert-circle"
        :description="error"
      />

      <UButton
        type="submit"
        color="neutral"
        variant="solid"
        size="sm"
        block
        :loading="submitting"
        :disabled="submitting"
      >
        {{ submitting ? 'Verificando…' : 'Verificar' }}
      </UButton>
    </form>

    <template #footer>
      <button
        type="button"
        class="w-full text-sm text-muted underline-offset-4 hover:text-ink hover:underline"
        @click="useRecovery = !useRecovery"
      >
        {{ useRecovery ? 'Usar código de la aplicación' : 'Usar código de recuperación' }}
      </button>
    </template>
  </AuthLayout>
</template>
