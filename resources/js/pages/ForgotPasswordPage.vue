<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';

import AppLayout from '@/layouts/AppLayout.vue';
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
  <AppLayout title="Recuperar contraseña">
    <section class="mx-auto max-w-md rounded-2xl border border-line bg-surface/60 p-6 shadow-2xl shadow-black/20">
      <p
        v-if="!sent"
        class="text-sm text-muted"
      >
        Ingresa tu correo y te enviaremos un enlace para restablecer la contraseña.
      </p>
      <p
        v-else
        class="text-sm text-success-ink"
      >
        Si el correo existe, te enviamos un enlace para restablecer la contraseña.
      </p>

      <form
        v-if="!sent"
        class="mt-6 space-y-4"
        @submit.prevent="submit"
      >
        <div>
          <label
            class="block text-xs font-medium uppercase tracking-wide text-muted"
            for="email"
          >
            Correo
          </label>
          <input
            id="email"
            v-model="email"
            type="email"
            autocomplete="email"
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
          :disabled="submitting"
          class="w-full rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white transition hover:bg-accent-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/60 disabled:opacity-60"
        >
          {{ submitting ? 'Enviando…' : 'Enviar enlace' }}
        </button>
      </form>

      <button
        v-if="sent"
        type="button"
        class="mt-6 w-full rounded-lg border border-line-strong bg-raised px-4 py-2 text-sm font-medium transition hover:bg-line-strong"
        @click="router.push({ name: 'login' })"
      >
        Volver a iniciar sesión
      </button>
    </section>
  </AppLayout>
</template>
