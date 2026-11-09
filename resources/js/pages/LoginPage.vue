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
    <section class="mx-auto max-w-md rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6 shadow-2xl shadow-black/20">
      <p class="text-sm text-zinc-400">
        Accede con las credenciales de tu estudio para continuar.
      </p>

      <form
        class="mt-6 space-y-4"
        @submit.prevent="submit"
      >
        <div>
          <label
            class="block text-xs font-medium uppercase tracking-wide text-zinc-400"
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
            class="mt-1 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm text-zinc-100 placeholder:text-zinc-600 focus:border-violet-500 focus:outline-none focus:ring-2 focus:ring-violet-500/40"
          >
        </div>

        <div>
          <label
            class="block text-xs font-medium uppercase tracking-wide text-zinc-400"
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
            class="mt-1 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm text-zinc-100 placeholder:text-zinc-600 focus:border-violet-500 focus:outline-none focus:ring-2 focus:ring-violet-500/40"
          >
        </div>

        <label class="flex items-center gap-2 text-sm text-zinc-400">
          <input
            v-model="remember"
            type="checkbox"
            class="size-4 rounded border-zinc-700 bg-zinc-950 text-violet-600 focus:ring-violet-500"
          >
          Recordarme
        </label>

        <p
          v-if="auth.error"
          class="rounded-lg border border-red-900 bg-red-950/60 px-4 py-3 text-sm text-red-200"
        >
          {{ auth.error }}
        </p>

        <button
          type="submit"
          :disabled="submitting"
          class="w-full rounded-lg bg-violet-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-violet-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-400 disabled:opacity-60"
        >
          {{ submitting ? 'Ingresando…' : 'Ingresar' }}
        </button>
      </form>

      <p class="mt-4 text-center text-sm text-zinc-400">
        <RouterLink
          :to="{ name: 'forgot-password' }"
          class="text-violet-400 underline-offset-4 hover:text-violet-300 hover:underline"
        >
          ¿Olvidaste tu contraseña?
        </RouterLink>
      </p>
    </section>
  </AppLayout>
</template>
