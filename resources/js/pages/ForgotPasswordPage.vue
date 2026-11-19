<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';

import AuthLayout from '@/layouts/AuthLayout.vue';
import { postJson } from '@/lib/api';

const router = useRouter();

const email = ref('');
const submitting = ref(false);
const sent = ref(false);
const error = ref<string | null>(null);

async function submit(): Promise<void> {
    if (submitting.value) {
        return;
    }

    submitting.value = true;
    error.value = null;

    try {
        await postJson('/api/v1/auth/forgot-password', { email: email.value });
        sent.value = true;
    } catch (caught: unknown) {
        error.value = caught instanceof Error ? caught.message : 'No se pudo enviar el enlace.';
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
  <AuthLayout
    title="Recuperar contraseña"
    subtitle="Te enviaremos un enlace para restablecer tu contraseña."
  >
    <UAlert
      v-if="sent"
      color="success"
      variant="soft"
      icon="i-lucide-check-circle"
      description="Si el correo existe, te enviamos un enlace para restablecer la contraseña."
    />

    <form
      v-if="!sent"
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
        {{ submitting ? 'Enviando…' : 'Enviar enlace' }}
      </UButton>
    </form>

    <template #footer>
      <UButton
        v-if="sent"
        color="neutral"
        variant="outline"
        size="lg"
        block
        @click="router.push({ name: 'login' })"
      >
        Volver a iniciar sesión
      </UButton>
    </template>
  </AuthLayout>
</template>
