<script setup lang="ts">
import AuthLayout from '@/layouts/AuthLayout.vue';
</script>

<template>
  <AuthLayout
    title="Página no encontrada"
    subtitle="La ruta que buscas no existe o fue movida."
  >
    <RouterLink
      to="/"
      class="inline-flex rounded-md border border-default bg-elevated px-4 py-2 text-sm font-medium text-highlighted transition hover:bg-elevated"
    >
      Volver al panel
    </RouterLink>
  </AuthLayout>
</template>
