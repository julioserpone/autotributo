<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';

import AppLayout from '@/layouts/AppLayout.vue';
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
  <AppLayout title="Verificación en dos pasos">
    <section class="mx-auto max-w-md rounded-2xl border border-line bg-surface/60 p-6 shadow-2xl shadow-black/20">
      <p class="text-sm text-muted">
        Ingresa el código de tu aplicación de autenticación.
      </p>

      <form
        class="mt-6 space-y-4"
        @submit.prevent="submit"
      >
        <template v-if="!useRecovery">
          <div>
            <label
              class="block text-xs font-medium uppercase tracking-wide text-muted"
              for="code"
            >
              Código
            </label>
            <input
              id="code"
              v-model="code"
              type="text"
              inputmode="numeric"
              autocomplete="one-time-code"
              required
              class="mt-1 w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink placeholder:text-faint focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30"
            >
          </div>
        </template>

        <template v-else>
          <div>
            <label
              class="block text-xs font-medium uppercase tracking-wide text-muted"
              for="recovery_code"
            >
              Código de recuperación
            </label>
            <input
              id="recovery_code"
              v-model="recoveryCode"
              type="text"
              required
              class="mt-1 w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink placeholder:text-faint focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30"
            >
          </div>
        </template>

        <p
          v-if="error"
          class="rounded-lg border border-danger/40 bg-danger-soft/60 px-4 py-3 text-sm text-danger-ink"
        >
          {{ error }}
        </p>

        <button
          type="submit"
          :disabled="submitting"
          class="w-full rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white transition hover:bg-accent-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/60 disabled:opacity-60"
        >
          {{ submitting ? 'Verificando…' : 'Verificar' }}
        </button>
      </form>

      <button
        type="button"
        class="mt-4 w-full text-sm text-muted underline-offset-4 hover:text-ink hover:underline"
        @click="useRecovery = !useRecovery"
      >
        {{ useRecovery ? 'Usar código de la aplicación' : 'Usar código de recuperación' }}
      </button>
    </section>
  </AppLayout>
</template>
