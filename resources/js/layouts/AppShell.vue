<script setup lang="ts">
import { onMounted } from 'vue';

import AppSidebar from '@/components/layout/AppSidebar.vue';
import { navigationGroups } from '@/lib/navigation';
import { useCompanyStore } from '@/stores/company';
import { useHealthStore } from '@/stores/health';

const companies = useCompanyStore();
const health = useHealthStore();

onMounted(() => {
    void health.check();

    if (!companies.loaded) {
        void companies.fetchCompanies();
    }
});
</script>

<template>
  <UDashboardGroup unit="rem">
    <a
      href="#contenido"
      class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-full focus:bg-primary focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-inverted"
    >
      Saltar al contenido
    </a>

    <AppSidebar />

    <UDashboardSearch
      title="Buscar"
      placeholder="Buscar secciones, empresas o comprobantes…"
      :groups="navigationGroups"
    />

    <slot />
  </UDashboardGroup>
</template>
