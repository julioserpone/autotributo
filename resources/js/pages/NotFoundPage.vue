<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
</script>

<template>
  <AppLayout title="Página no encontrada">
    <section class="rounded-2xl border border-line bg-surface/60 p-6">
      <p class="text-sm text-muted">
        La ruta que buscas no existe o fue movida.
      </p>
      <RouterLink
        to="/"
        class="mt-4 inline-flex rounded-lg border border-accent/40 bg-accent-soft px-4 py-2 text-sm font-medium text-accent-ink hover:bg-accent-soft"
      >
        Volver al panel
      </RouterLink>
    </section>
  </AppLayout>
</template>
