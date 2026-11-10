<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';

import AppLayout from '@/layouts/AppLayout.vue';
import { postJson } from '@/lib/api';

interface SetupData {
    secret: string;
    otpauth_url: string;
}

const router = useRouter();

const setup = ref<SetupData | null>(null);
const recoveryCodes = ref<string[]>([]);
const code = ref('');
const starting = ref(false);
const confirming = ref(false);
const copied = ref(false);
const error = ref<string | null>(null);

async function start(): Promise<void> {
    if (starting.value) {
        return;
    }

    starting.value = true;
    error.value = null;

    try {
        const response = await postJson<{ data: SetupData }>('/api/v1/auth/two-factor/enable', {});
        setup.value = response.data;
    } catch (caught: unknown) {
        error.value = caught instanceof Error ? caught.message : 'No se pudo iniciar la configuración.';
    } finally {
        starting.value = false;
    }
}

async function confirm(): Promise<void> {
    if (confirming.value) {
        return;
    }

    confirming.value = true;
    error.value = null;

    try {
        const response = await postJson<{ data: { recovery_codes: string[] } }>(
            '/api/v1/auth/two-factor/confirm',
            { code: code.value },
        );

        recoveryCodes.value = response.data.recovery_codes;
    } catch (caught: unknown) {
        error.value = caught instanceof Error ? caught.message : 'No se pudo verificar el código.';
    } finally {
        confirming.value = false;
    }
}

async function copySecret(): Promise<void> {
    if (!setup.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(setup.value.secret);
        copied.value = true;
    } catch {
        copied.value = false;
    }
}

async function goToDashboard(): Promise<void> {
    await router.push({ name: 'dashboard' });
}
</script>

<template>
  <AppLayout title="Verificación en dos pasos">
    <section class="mx-auto max-w-md rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6 shadow-2xl shadow-black/20">
      <template v-if="recoveryCodes.length > 0">
        <h2 class="text-lg font-semibold">
          Verificación activada
        </h2>
        <p class="mt-2 text-sm text-zinc-400">
          Guarda estos códigos de recuperación en un lugar seguro. No se volverán a mostrar.
        </p>

        <ul class="mt-4 grid grid-cols-1 gap-2 rounded-lg border border-zinc-800 bg-zinc-950 p-4 font-mono text-sm sm:grid-cols-2">
          <li
            v-for="recoveryCode in recoveryCodes"
            :key="recoveryCode"
          >
            {{ recoveryCode }}
          </li>
        </ul>

        <button
          type="button"
          class="mt-6 w-full rounded-lg bg-violet-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-violet-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-400"
          @click="goToDashboard"
        >
          Ir al panel
        </button>
      </template>

      <template v-else-if="setup">
        <p class="text-sm text-zinc-400">
          Abre tu aplicación de autenticación y añade una cuenta con esta clave:
        </p>

        <div class="mt-4 flex items-center gap-2 rounded-lg border border-zinc-800 bg-zinc-950 p-3">
          <code class="flex-1 break-all font-mono text-sm text-violet-300">{{ setup.secret }}</code>
          <button
            type="button"
            class="rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-1 text-xs font-medium transition hover:bg-zinc-700"
            @click="copySecret"
          >
            {{ copied ? 'Copiado' : 'Copiar' }}
          </button>
        </div>

        <a
          :href="setup.otpauth_url"
          class="mt-2 block truncate text-xs text-zinc-500 hover:text-zinc-300"
        >
          Abrir enlace otpauth
        </a>

        <form
          class="mt-6 space-y-4"
          @submit.prevent="confirm"
        >
          <div>
            <label
              class="block text-xs font-medium uppercase tracking-wide text-zinc-400"
              for="code"
            >
              Código de 6 dígitos
            </label>
            <input
              id="code"
              v-model="code"
              type="text"
              inputmode="numeric"
              autocomplete="one-time-code"
              required
              class="mt-1 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm text-zinc-100 placeholder:text-zinc-600 focus:border-violet-500 focus:outline-none focus:ring-2 focus:ring-violet-500/40"
            >
          </div>

          <p
            v-if="error"
            class="rounded-lg border border-red-900 bg-red-950/60 px-4 py-3 text-sm text-red-200"
          >
            {{ error }}
          </p>

          <button
            type="submit"
            :disabled="confirming"
            class="w-full rounded-lg bg-violet-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-violet-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-400 disabled:opacity-60"
          >
            {{ confirming ? 'Verificando…' : 'Activar verificación' }}
          </button>
        </form>
      </template>

      <template v-else>
        <p class="text-sm text-zinc-400">
          Tu rol de owner exige la verificación en dos pasos antes de acceder a los endpoints de negocio.
        </p>

        <p
          v-if="error"
          class="mt-4 rounded-lg border border-red-900 bg-red-950/60 px-4 py-3 text-sm text-red-200"
        >
          {{ error }}
        </p>

        <button
          type="button"
          :disabled="starting"
          class="mt-6 w-full rounded-lg bg-violet-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-violet-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-400 disabled:opacity-60"
          @click="start"
        >
          {{ starting ? 'Generando clave…' : 'Comenzar configuración' }}
        </button>
      </template>
    </section>
  </AppLayout>
</template>
