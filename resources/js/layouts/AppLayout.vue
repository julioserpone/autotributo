<script setup lang="ts">
import { useRouter } from 'vue-router';

import { useAuthStore } from '@/stores/auth';

defineProps<{
    title: string;
}>();

const router = useRouter();
const auth = useAuthStore();

async function logout(): Promise<void> {
    await auth.logout();
    await router.push({ name: 'login' });
}
</script>

<template>
  <div class="min-h-screen bg-zinc-950 text-zinc-100">
    <header class="border-b border-zinc-800 bg-zinc-900/70 backdrop-blur">
      <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-6 py-4">
        <div class="flex items-center gap-3">
          <span class="grid size-9 place-items-center rounded-lg bg-violet-600 font-bold text-white">
            A
          </span>
          <div>
            <p class="text-sm font-semibold tracking-tight">
              Autotributo
            </p>
            <p class="text-xs text-zinc-400">
              Contabilidad SRI · Ecuador
            </p>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <slot name="actions" />
          <button
            v-if="auth.user"
            type="button"
            class="rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm font-medium transition hover:bg-zinc-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500"
            @click="logout"
          >
            Cerrar sesión
          </button>
        </div>
      </div>
    </header>

    <main class="mx-auto max-w-5xl px-6 py-10">
      <div class="mb-8">
        <p class="text-xs font-medium uppercase tracking-[0.2em] text-violet-400">
          MVP · Fase 1
        </p>
        <h1 class="mt-2 text-3xl font-semibold tracking-tight">
          {{ title }}
        </h1>
      </div>
      <slot />
    </main>
  </div>
</template>
