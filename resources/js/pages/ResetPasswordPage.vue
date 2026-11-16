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
    <section class="mx-auto max-w-md rounded-2xl border border-line bg-surface/60 p-6 shadow-2xl shadow-black/20">
      <p
        v-if="!done"
        class="text-sm text-muted"
      >
        Define tu nueva contraseña.
      </p>
      <p
        v-else
        class="text-sm text-success-ink"
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

        <div>
          <label
            class="block text-xs font-medium uppercase tracking-wide text-muted"
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
            class="mt-1 w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink placeholder:text-faint focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30"
          >
        </div>

        <div>
          <label
            class="block text-xs font-medium uppercase tracking-wide text-muted"
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
          {{ submitting ? 'Restableciendo…' : 'Restablecer' }}
        </button>
      </form>

      <button
        v-if="done"
        type="button"
        class="mt-6 w-full rounded-lg border border-line-strong bg-raised px-4 py-2 text-sm font-medium transition hover:bg-line-strong"
        @click="router.push({ name: 'login' })"
      >
        Ir a iniciar sesión
      </button>
    </section>
  </AppLayout>
</template>
