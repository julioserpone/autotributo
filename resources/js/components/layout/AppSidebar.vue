<script setup lang="ts">
import { ref, watch } from 'vue';
import { useRoute } from 'vue-router';

import AppUserMenu from '@/components/layout/AppUserMenu.vue';
import AppWorkspaceMenu from '@/components/layout/AppWorkspaceMenu.vue';
import { primaryMenu, systemMenu } from '@/lib/navigation';

const route = useRoute();
const open = ref(false);

// En móvil la barra es un slideover: al navegar se cierra sola.
watch(() => route.fullPath, () => {
    open.value = false;
});
</script>

<template>
  <UDashboardSidebar
    id="app"
    v-model:open="open"
    collapsible
    resizable
    :default-size="17"
    :min-size="14"
    :max-size="22"
    class="bg-elevated/25"
    :ui="{ footer: 'lg:border-t lg:border-default' }"
  >
    <template #header="{ collapsed }">
      <AppWorkspaceMenu :collapsed="collapsed" />
    </template>

    <template #default="{ collapsed }">
      <UDashboardSearchButton
        :collapsed="collapsed"
        class="bg-transparent ring-default"
      />

      <UNavigationMenu
        :collapsed="collapsed"
        :items="primaryMenu"
        orientation="vertical"
        tooltip
        popover
      />

      <UNavigationMenu
        :collapsed="collapsed"
        :items="systemMenu"
        orientation="vertical"
        tooltip
        class="mt-auto"
      />
    </template>

    <template #footer="{ collapsed }">
      <AppUserMenu :collapsed="collapsed" />
    </template>
  </UDashboardSidebar>
</template>
