<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter, useRoute } from 'vue-router';

import AuthLayout from '@/layouts/AuthLayout.vue';
import { postJson } from '@/lib/api';

const route = useRoute();
const router = useRouter();

const token = computed(() => String(route.query.token ?? ''));
const email = ref(String(route.query.email ?? ''));

const password = ref('');
const passwordConfirmation = ref('');
const submitting = ref(false);
const done = ref(false);
const error = ref<string | null>(null);

async function submit(): Promise<void> {
    if (submitting.value) {
        return;
    }

    submitting.value = true;
    error.value = null;

    try {
        await postJson('/api/v1/auth/reset-password', {
            token: token.value,
            email: email.value,
            password: password.value,
            password_confirmation: passwordConfirmation.value,
        });
        done.value = true;
    } catch (caught: unknown) {
        error.value = caught instanceof Error ? caught.message : 'No se pudo restablecer la contraseña.';
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
  <AuthLayout
    title="Restablecer contraseña"
    subtitle="Define tu nueva contraseña."
  >
    <UAlert
      v-if="done"
      color="success"
      variant="soft"
      icon="i-lucide-check-circle"
      description="Tu contraseña se restableció correctamente."
    />

    <form
      v-if="!done"
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
          class="w-full bg-elevated/40"
          type="email"
          autocomplete="email"
          required
          icon="i-lucide-mail"
          size="lg"
          color="neutral"
          variant="outline"
        />
      </UFormField>

      <UFormField
        label="Nueva contraseña"
        name="password"
      >
        <UInput
          id="password"
          v-model="password"
          class="w-full bg-elevated/40"
          type="password"
          autocomplete="new-password"
          required
          icon="i-lucide-lock"
          size="lg"
          color="neutral"
          variant="outline"
        />
      </UFormField>

      <UFormField
        label="Confirmar contraseña"
        name="password_confirmation"
      >
        <UInput
          id="password_confirmation"
          v-model="passwordConfirmation"
          class="w-full bg-elevated/40"
          type="password"
          autocomplete="new-password"
          required
          icon="i-lucide-lock"
          size="lg"
          color="neutral"
          variant="outline"
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
        color="primary"
        variant="solid"
        size="lg"
        block
        :loading="submitting"
        :disabled="submitting"
      >
        {{ submitting ? 'Restableciendo…' : 'Restablecer' }}
      </UButton>
    </form>

    <template #footer>
      <UButton
        v-if="done"
        color="neutral"
        variant="outline"
        size="lg"
        block
        @click="router.push({ name: 'login' })"
      >
        Ir a iniciar sesión
      </UButton>
    </template>
  </AuthLayout>
</template>
