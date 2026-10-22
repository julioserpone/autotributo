<script setup lang="ts">
import { storeToRefs } from 'pinia';
import { onMounted } from 'vue';

import AppStatusBadge from '@/components/AppStatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useHealthStore } from '@/stores/health';

const health = useHealthStore();
const { status, data, error } = storeToRefs(health);

onMounted(() => {
    void health.check();
});
</script>

<template>
  <AppLayout title="Panel">
    <section class="rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6 shadow-2xl shadow-black/20">
      <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h2 class="text-lg font-semibold">
            Base del producto lista
          </h2>
          <p class="mt-1 max-w-2xl text-sm text-zinc-400">
            Laravel 13, Vue 3 con TypeScript estricto y el pipeline de calidad están conectados.
            El siguiente paso es la tenancy single-tenant y la identidad (F1-02/F1-03).
          </p>
        </div>
        <AppStatusBadge :status="status" />
      </div>

      <dl
        v-if="data"
        class="mt-6 grid gap-4 border-t border-zinc-800 pt-6 sm:grid-cols-3"
      >
        <div>
          <dt class="text-xs uppercase tracking-wide text-zinc-500">
            Aplicación
          </dt>
          <dd class="mt-1 text-sm font-medium">
            {{ data.app }}
          </dd>
        </div>
        <div>
          <dt class="text-xs uppercase tracking-wide text-zinc-500">
            Ambiente
          </dt>
          <dd class="mt-1 text-sm font-medium">
            {{ data.environment }}
          </dd>
        </div>
        <div>
          <dt class="text-xs uppercase tracking-wide text-zinc-500">
            Versión
          </dt>
          <dd class="mt-1 text-sm font-medium">
            {{ data.version }}
          </dd>
        </div>
      </dl>

      <p
        v-if="error"
        class="mt-6 rounded-lg border border-red-900 bg-red-950/60 px-4 py-3 text-sm text-red-200"
      >
        {{ error }}
      </p>

      <button
        type="button"
        class="mt-6 rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2 text-sm font-medium transition hover:bg-zinc-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500"
        @click="health.check()"
      >
        Reintentar
      </button>
    </section>
  </AppLayout>
</template>
