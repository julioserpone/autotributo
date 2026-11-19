<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';

import AppThemeMenu from '@/components/layout/AppThemeMenu.vue';

defineProps<{
    title: string;
    description?: string;
}>();

const route = useRoute();

/** Identificador estable del panel para recordar el tamaño del sidebar. */
const panelId = computed(() => String(route.name ?? 'page'));
</script>

<template>
  <UDashboardPanel :id="panelId">
    <template #header>
      <UDashboardNavbar :title="title">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <slot name="actions" />
          <AppThemeMenu />
        </template>
      </UDashboardNavbar>

      <UDashboardToolbar v-if="$slots.toolbar || $slots['toolbar-right']">
        <template #left>
          <slot name="toolbar" />
        </template>
        <template #right>
          <slot name="toolbar-right" />
        </template>
      </UDashboardToolbar>
    </template>

    <template #body>
      <main
        id="contenido"
        class="flex flex-1 flex-col gap-4 sm:gap-6"
      >
        <p
          v-if="description"
          class="text-sm text-muted"
        >
          {{ description }}
        </p>

        <slot />
      </main>
    </template>
  </UDashboardPanel>
</template>
