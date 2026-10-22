<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
</script>

<template>
  <AppLayout title="Página no encontrada">
    <section class="rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6">
      <p class="text-sm text-zinc-400">
        La ruta que buscas no existe o fue movida.
      </p>
      <RouterLink
        to="/"
        class="mt-4 inline-flex rounded-lg border border-violet-700 bg-violet-950 px-4 py-2 text-sm font-medium text-violet-200 hover:bg-violet-900"
      >
        Volver al panel
      </RouterLink>
    </section>
  </AppLayout>
</template>
