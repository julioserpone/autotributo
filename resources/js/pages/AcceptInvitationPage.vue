<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';

import { useRoute, useRouter } from 'vue-router';

import AuthLayout from '@/layouts/AuthLayout.vue';
import PasswordRevealButton from '@/components/ui/PasswordRevealButton.vue';
import { getJson, postJson } from '@/lib/api';

const route = useRoute();
const router = useRouter();

const token = computed(() => (typeof route.query.token === 'string' ? route.query.token : ''));

const name = ref('');
const password = ref('');
const confirmation = ref('');
const loading = ref(false);
const validating = ref(false);
const invalid = ref(false);
const error = ref<string | null>(null);
const success = ref<string | null>(null);

const revealPassword = ref(false);
const revealConfirmation = ref(false);

const canSubmit = computed(() => name.value.trim().length > 0 && password.value.length >= 8 && password.value === confirmation.value);

onMounted(async () => {
    if (!token.value) {
        invalid.value = true;
        error.value = 'El enlace de invitación no es válido.';

        return;
    }

    validating.value = true;

    try {
        await getJson(`/api/v1/auth/invitations/validate?token=${encodeURIComponent(token.value)}`);
    } catch (caught: unknown) {
        invalid.value = true;
        error.value = caught instanceof Error ? caught.message : 'La invitación no es válida, fue anulada o ha expirado.';
    } finally {
        validating.value = false;
    }
});

async function submit(): Promise<void> {
    if (!token.value) {
        error.value = 'El enlace de invitación no es válido.';
        return;
    }

    if (!canSubmit.value) {
        error.value = 'Completa todos los campos correctamente.';
        return;
    }

    loading.value = true;
    error.value = null;

    try {
        await postJson('/api/v1/auth/invitations/accept', {
            token: token.value,
            name: name.value.trim(),
            password: password.value,
            password_confirmation: confirmation.value,
        });

        success.value = 'Tu cuenta fue creada. Ya puedes iniciar sesión.';

        setTimeout(() => {
            void router.push({ name: 'login' });
        }, 1500);
    } catch (caught: unknown) {
        error.value = caught instanceof Error ? caught.message : 'No se pudo aceptar la invitación.';
    } finally {
        loading.value = false;
    }
}
</script>

<template>
  <AuthLayout
    title="Aceptar invitación"
    subtitle="Completa tus datos para unirte al estudio."
  >
    <div class="space-y-6">
      <UAlert
        v-if="!token"
        color="error"
        variant="soft"
        icon="i-lucide-alert-circle"
        title="Enlace inválido"
        description="El enlace de invitación no contiene un token válido."
      />

      <div
        v-else-if="validating"
        class="flex items-center justify-center py-6 text-muted"
      >
        <UIcon
          name="i-lucide-loader-2"
          class="size-5 animate-spin"
        />
      </div>

      <UAlert
        v-else-if="invalid"
        color="error"
        variant="soft"
        icon="i-lucide-alert-circle"
        title="Invitación no válida"
        :description="error ?? 'La invitación no es válida, fue anulada o ha expirado.'"
      />

      <form
        v-else-if="!invalid"
        class="space-y-4"
        @submit.prevent="submit"
      >
        <UFormField
          label="Nombre completo"
          name="name"
        >
          <UInput
            v-model="name"
            color="neutral"
            variant="outline"
            class="w-full"
            autocomplete="name"
          />
        </UFormField>

        <UFormField
          label="Contraseña"
          name="password"
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
          v-if="error"
          color="error"
          variant="soft"
          icon="i-lucide-alert-circle"
          :description="error"
        />

        <UAlert
          v-if="success"
          color="success"
          variant="soft"
          icon="i-lucide-check-circle"
          :description="success"
        />

        <UButton
          color="primary"
          size="md"
          block
          label="Aceptar invitación"
          :loading="loading"
          :disabled="loading || !canSubmit"
          type="submit"
        />
      </form>
    </div>
  </AuthLayout>
</template>