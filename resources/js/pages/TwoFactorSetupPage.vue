<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { renderSVG } from 'uqr';

import AppShell from '@/layouts/AppShell.vue';
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
  <AppShell title="Seguridad">
    <div class="mx-auto max-w-2xl">
      <section class="rounded-lg border border-line bg-surface p-6 shadow-2xs dark:shadow-none">
        <h1 class="text-xl font-semibold tracking-tight text-ink">
          Verificación en dos pasos
        </h1>
        <p class="mt-1 text-sm text-muted">
          Tu rol de owner exige activar la verificación en dos pasos.
        </p>

        <div
          v-if="recoveryCodes.length > 0"
          class="mt-5 space-y-4"
        >
          <UAlert
            color="success"
            variant="soft"
            icon="i-lucide-check-circle"
            title="Verificación activada"
            description="Guarda estos códigos de recuperación en un lugar seguro. No se volverán a mostrar."
          />

          <ul class="grid grid-cols-1 gap-2 rounded-lg border border-line bg-canvas p-4 font-mono text-sm sm:grid-cols-2">
            <li
              v-for="recoveryCode in recoveryCodes"
              :key="recoveryCode"
              class="text-ink"
            >
              {{ recoveryCode }}
            </li>
          </ul>

          <UButton
            color="neutral"
            variant="solid"
            size="lg"
            block
            @click="goToDashboard"
          >
            Ir al panel
          </UButton>
        </div>

        <div
          v-else-if="setup"
          class="mt-5 space-y-4"
        >
          <p class="text-sm text-muted">
            Escanea este código con Google Authenticator, Authy u otra aplicación TOTP:
          </p>

          <img
            :src="qrSrc"
            alt="Código QR para configurar la verificación en dos pasos"
            class="mx-auto size-52 rounded-xl bg-white p-3"
          >

          <p class="text-center text-xs text-faint">
            ¿No puedes escanearlo? Añade la cuenta manualmente con esta clave:
          </p>

          <div class="flex items-center gap-2 rounded-lg border border-line bg-canvas p-3">
            <code class="flex-1 break-all font-mono text-sm text-ink">{{ setup.secret }}</code>
            <UButton
              color="neutral"
              variant="outline"
              size="xs"
              @click="copySecret"
            >
              {{ copied ? 'Copiado' : 'Copiar' }}
            </UButton>
          </div>

          <a
            :href="setup.otpauth_url"
            class="block truncate text-xs text-faint hover:text-ink"
          >
            Abrir enlace otpauth
          </a>

          <form
            class="space-y-4"
            @submit.prevent="confirm"
          >
            <UFormField
              label="Código de 6 dígitos"
              name="code"
            >
              <UInput
                id="code"
                v-model="code"
                class="w-full"
                type="text"
                inputmode="numeric"
                autocomplete="one-time-code"
                required
                icon="i-lucide-key-round"
                size="lg"
              />
            </UFormField>

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
              size="lg"
              block
              :loading="confirming"
              :disabled="confirming"
            >
              {{ confirming ? 'Verificando…' : 'Activar verificación' }}
            </UButton>
          </form>
        </div>

        <div
          v-else
          class="mt-5 space-y-4"
        >
          <p class="text-sm text-muted">
            Tu rol de owner exige la verificación en dos pasos antes de acceder a los endpoints de negocio.
          </p>

          <UAlert
            v-if="error"
            color="error"
            variant="soft"
            icon="i-lucide-alert-circle"
            :description="error"
          />

          <UButton
            color="neutral"
            variant="solid"
            size="lg"
            block
            :loading="starting"
            :disabled="starting"
            @click="start"
          >
            {{ starting ? 'Generando clave…' : 'Comenzar configuración' }}
          </UButton>
        </div>
      </section>
    </div>
  </AppShell>
</template>
