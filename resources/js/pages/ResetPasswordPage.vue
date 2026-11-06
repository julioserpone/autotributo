<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import AppLayout from '@/layouts/AppLayout.vue';
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
  <AppLayout title="Restablecer contraseña">
    <section class="mx-auto max-w-md rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6 shadow-2xl shadow-black/20">
      <p
        v-if="!done"
        class="text-sm text-zinc-400"
      >
        Define tu nueva contraseña.
      </p>
      <p
        v-else
        class="text-sm text-emerald-300"
      >
        Tu contraseña se restableció correctamente.
      </p>

      <form
        v-if="!done"
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
            autocomplete="email"
            required
            class="mt-1 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm text-zinc-100 placeholder:text-zinc-600 focus:border-violet-500 focus:outline-none focus:ring-2 focus:ring-violet-500/40"
          >
        </div>

        <div>
          <label
            class="block text-xs font-medium uppercase tracking-wide text-zinc-400"
            for="password"
          >
            Nueva contraseña
          </label>
          <input
            id="password"
            v-model="password"
            type="password"
            autocomplete="new-password"
            required
            class="mt-1 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm text-zinc-100 placeholder:text-zinc-600 focus:border-violet-500 focus:outline-none focus:ring-2 focus:ring-violet-500/40"
          >
        </div>

        <div>
          <label
            class="block text-xs font-medium uppercase tracking-wide text-zinc-400"
            for="password_confirmation"
          >
            Confirmar contraseña
          </label>
          <input
            id="password_confirmation"
            v-model="passwordConfirmation"
            type="password"
            autocomplete="new-password"
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
          :disabled="submitting"
          class="w-full rounded-lg bg-violet-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-violet-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-400 disabled:opacity-60"
        >
          {{ submitting ? 'Restableciendo…' : 'Restablecer' }}
        </button>
      </form>

      <button
        v-if="done"
        type="button"
        class="mt-6 w-full rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2 text-sm font-medium transition hover:bg-zinc-700"
        @click="router.push({ name: 'login' })"
      >
        Ir a iniciar sesión
      </button>
    </section>
  </AppLayout>
</template>
