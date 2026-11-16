<script setup lang="ts">
import { useRouter } from 'vue-router';

import AppButton from '@/components/ui/AppButton.vue';
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
  <div class="min-h-screen bg-canvas text-ink">
    <a
      href="#contenido"
      class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-control focus:bg-accent focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-white"
    >
      Saltar al contenido
    </a>

    <header class="sticky top-0 z-30 border-b border-line bg-surface/70 backdrop-blur">
      <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-6 py-4">
        <div class="flex items-center gap-3">
          <span class="grid size-9 place-items-center rounded-control bg-accent font-bold text-white">
            A
          </span>
          <div>
            <p class="text-sm font-semibold tracking-tight">
              Autotributo
            </p>
            <p class="text-xs text-muted">
              Contabilidad SRI · Ecuador
            </p>
          </div>
        </div>

        <div class="flex items-center gap-2">
          <slot name="actions" />
          <AppButton
            v-if="auth.user"
            variant="secondary"
            size="sm"
            @click="logout"
          >
            Cerrar sesión
          </AppButton>
        </div>
      </div>
    </header>

    <main
      id="contenido"
      class="mx-auto max-w-5xl px-6 py-10"
    >
      <div class="mb-8">
        <p class="text-2xs font-medium uppercase tracking-[0.2em] text-accent-ink">
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
