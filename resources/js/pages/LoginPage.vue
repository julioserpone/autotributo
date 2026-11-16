<script setup lang="ts">
import { ref } from 'vue';
import { RouterLink, useRouter } from 'vue-router';

import AuthLayout from '@/layouts/AuthLayout.vue';
import { useAuthStore } from '@/stores/auth';

const router = useRouter();
const auth = useAuthStore();

const email = ref('');
const password = ref('');
const remember = ref(false);
const submitting = ref(false);

async function submit(): Promise<void> {
    if (submitting.value) {
        return;
    }

    submitting.value = true;
    auth.error = null;

    try {
        const result = await auth.login({
            email: email.value,
            password: password.value,
            remember: remember.value,
        });

        if (result?.two_factor_required) {
            await router.push({ name: 'two-factor-challenge' });
        } else if (result?.two_factor_setup_required) {
            await router.push({ name: 'two-factor-setup' });
        } else {
            await router.push({ name: 'dashboard' });
        }
    } catch {
        // El mensaje ya quedó en el store.
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
  <AuthLayout
    title="Iniciar sesión"
    subtitle="Accede con las credenciales de tu estudio para continuar."
  >
    <form
      class="space-y-4"
      @submit.prevent="submit"
    >
      <UFormField
        label="Correo"
        name="email"
      >
        <UInput
          id="email"
          v-model="email"
          type="email"
          autocomplete="username"
          required
          icon="i-lucide-mail"
          size="sm"
        />
      </UFormField>

      <UFormField
        label="Contraseña"
        name="password"
      >
        <UInput
          id="password"
          v-model="password"
          type="password"
          autocomplete="current-password"
          required
          icon="i-lucide-lock"
          size="sm"
        />
      </UFormField>

      <UCheckbox
        v-model="remember"
        label="Recordarme"
      />

      <UAlert
        v-if="auth.error"
        color="error"
        variant="soft"
        icon="i-lucide-alert-circle"
        :description="auth.error"
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
        {{ submitting ? 'Ingresando…' : 'Ingresar' }}
      </UButton>
    </form>

    <template #footer>
      <p class="text-center text-sm text-muted">
        <RouterLink
          :to="{ name: 'forgot-password' }"
          class="text-ink underline-offset-4 hover:underline"
        >
          ¿Olvidaste tu contraseña?
        </RouterLink>
      </p>
    </template>
  </AuthLayout>
</template>
