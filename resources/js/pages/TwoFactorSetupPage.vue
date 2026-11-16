<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { renderSVG } from 'uqr';

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

/**
 * QR generado en el cliente a partir del otpauth_url. Evita enviar el secreto
 * a servicios externos de generación de imágenes.
 */
const qrSrc = computed((): string => {
    if (!setup.value) {
        return '';
    }

    const svg = renderSVG(setup.value.otpauth_url, {
        ecc: 'M',
        border: 2,
        blackColor: '#000000',
        whiteColor: '#ffffff',
    });

    return `data:image/svg+xml;utf8,${encodeURIComponent(svg)}`;
});

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
    <section class="mx-auto max-w-md rounded-2xl border border-line bg-surface/60 p-6 shadow-2xl shadow-black/20">
      <template v-if="recoveryCodes.length > 0">
        <h2 class="text-lg font-semibold">
          Verificación activada
        </h2>
        <p class="mt-2 text-sm text-muted">
          Guarda estos códigos de recuperación en un lugar seguro. No se volverán a mostrar.
        </p>

        <ul class="mt-4 grid grid-cols-1 gap-2 rounded-lg border border-line bg-canvas p-4 font-mono text-sm sm:grid-cols-2">
          <li
            v-for="recoveryCode in recoveryCodes"
            :key="recoveryCode"
          >
            {{ recoveryCode }}
          </li>
        </ul>

        <button
          type="button"
          class="mt-6 w-full rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white transition hover:bg-accent-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/60"
          @click="goToDashboard"
        >
          Ir al panel
        </button>
      </template>

      <template v-else-if="setup">
        <p class="text-sm text-muted">
          Escanea este código con Google Authenticator, Authy u otra aplicación TOTP:
        </p>

        <img
          :src="qrSrc"
          alt="Código QR para configurar la verificación en dos pasos"
          class="mx-auto mt-4 size-52 rounded-xl bg-white p-3"
        >

        <p class="mt-4 text-center text-xs text-faint">
          ¿No puedes escanearlo? Añade la cuenta manualmente con esta clave:
        </p>

        <div class="mt-2 flex items-center gap-2 rounded-lg border border-line bg-canvas p-3">
          <code class="flex-1 break-all font-mono text-sm text-accent-ink">{{ setup.secret }}</code>
          <button
            type="button"
            class="rounded-lg border border-line-strong bg-raised px-3 py-1 text-xs font-medium transition hover:bg-line-strong"
            @click="copySecret"
          >
            {{ copied ? 'Copiado' : 'Copiar' }}
          </button>
        </div>

        <a
          :href="setup.otpauth_url"
          class="mt-2 block truncate text-xs text-faint hover:text-ink"
        >
          Abrir enlace otpauth
        </a>

        <form
          class="mt-6 space-y-4"
          @submit.prevent="confirm"
        >
          <div>
            <label
              class="block text-xs font-medium uppercase tracking-wide text-muted"
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
              class="mt-1 w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink placeholder:text-faint focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30"
            >
          </div>

          <p
            v-if="error"
            class="rounded-lg border border-danger/40 bg-danger-soft/60 px-4 py-3 text-sm text-danger-ink"
          >
            {{ error }}
          </p>

          <button
            type="submit"
            :disabled="confirming"
            class="w-full rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white transition hover:bg-accent-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/60 disabled:opacity-60"
          >
            {{ confirming ? 'Verificando…' : 'Activar verificación' }}
          </button>
        </form>
      </template>

      <template v-else>
        <p class="text-sm text-muted">
          Tu rol de owner exige la verificación en dos pasos antes de acceder a los endpoints de negocio.
        </p>

        <p
          v-if="error"
          class="mt-4 rounded-lg border border-danger/40 bg-danger-soft/60 px-4 py-3 text-sm text-danger-ink"
        >
          {{ error }}
        </p>

        <button
          type="button"
          :disabled="starting"
          class="mt-6 w-full rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white transition hover:bg-accent-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/60 disabled:opacity-60"
          @click="start"
        >
          {{ starting ? 'Generando clave…' : 'Comenzar configuración' }}
        </button>
      </template>
    </section>
  </AppLayout>
</template>
