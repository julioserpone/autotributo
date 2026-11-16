<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';

import AppLayout from '@/layouts/AppLayout.vue';
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
  <AppLayout title="Iniciar sesión">
    <section class="mx-auto max-w-md rounded-2xl border border-line bg-surface/60 p-6 shadow-2xl shadow-black/20">
      <p class="text-sm text-muted">
        Accede con las credenciales de tu estudio para continuar.
      </p>

      <form
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
            autocomplete="username"
            required
            class="mt-1 w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink placeholder:text-faint focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30"
          >
        </div>

        <div>
          <label
            class="block text-xs font-medium uppercase tracking-wide text-muted"
            for="password"
          >
            Contraseña
          </label>
          <input
            id="password"
            v-model="password"
            type="password"
            autocomplete="current-password"
            required
            class="mt-1 w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink placeholder:text-faint focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30"
          >
        </div>

        <label class="flex items-center gap-2 text-sm text-muted">
          <input
            v-model="remember"
            type="checkbox"
            class="size-4 rounded border-line-strong bg-canvas text-accent focus:ring-accent/30"
          >
          Recordarme
        </label>

        <p
          v-if="auth.error"
          class="rounded-lg border border-danger/40 bg-danger-soft/60 px-4 py-3 text-sm text-danger-ink"
        >
          {{ auth.error }}
        </p>

        <button
          type="submit"
          :disabled="submitting"
          class="w-full rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white transition hover:bg-accent-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/60 disabled:opacity-60"
        >
          {{ submitting ? 'Ingresando…' : 'Ingresar' }}
        </button>
      </form>

      <p class="mt-4 text-center text-sm text-muted">
        <RouterLink
          :to="{ name: 'forgot-password' }"
          class="text-accent-ink underline-offset-4 hover:text-accent-ink hover:underline"
        >
          ¿Olvidaste tu contraseña?
        </RouterLink>
      </p>
    </section>
  </AppLayout>
</template>
