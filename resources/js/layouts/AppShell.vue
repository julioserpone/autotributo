<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';

import AppSidebar from '@/components/AppSidebar.vue';
import AppStatusBadge from '@/components/AppStatusBadge.vue';
import AppThemeToggle from '@/components/AppThemeToggle.vue';
import { useCompanyStore } from '@/stores/company';
import { useHealthStore } from '@/stores/health';

const props = defineProps<{
    title?: string;
}>();

const route = useRoute();
const companies = useCompanyStore();
const health = useHealthStore();

const mobileOpen = ref(false);

const pageTitle = computed(() => props.title ?? (route.meta.title as string | undefined) ?? 'Panel');

const companyName = computed(() => {
    const company = companies.activeCompany;
    return company?.trade_name || company?.legal_name || 'Sin empresa';
});

watch(() => route.fullPath, () => {
    mobileOpen.value = false;
});

onMounted(() => {
    void health.check();
    if (!companies.loaded) {
        void companies.fetchCompanies();
    }
});
</script>

<template>
  <div class="flex h-screen overflow-hidden bg-canvas text-ink">
    <a
      href="#contenido"
      class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-control focus:bg-accent focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-accent-fg"
    >
      Saltar al contenido
    </a>

    <!-- Overlay móvil -->
    <div
      v-if="mobileOpen"
      class="fixed inset-0 z-30 bg-black/60 backdrop-blur-sm lg:hidden"
      @click="mobileOpen = false"
    />

    <!-- Sidebar: fija en móvil, estática en escritorio -->
    <div
      class="fixed inset-y-0 left-0 z-40 w-64 -translate-x-full transform transition-transform duration-200 ease-out lg:static lg:z-auto lg:translate-x-0 lg:shrink-0"
      :class="{ 'translate-x-0': mobileOpen }"
    >
      <AppSidebar class="h-full" />
    </div>

    <div class="flex min-w-0 flex-1 flex-col overflow-hidden">
      <header class="shrink-0 flex h-14 items-center justify-between gap-3 border-b border-line bg-surface/80 px-4 backdrop-blur-md lg:px-8">
        <div class="flex min-w-0 items-center gap-3">
          <button
            type="button"
            class="inline-flex items-center justify-center rounded-md border border-line bg-surface p-2 text-muted transition hover:bg-elevated hover:text-ink lg:hidden"
            aria-label="Abrir menú"
            @click="mobileOpen = true"
          >
            <UIcon
              name="i-lucide-menu"
              class="size-4"
            />
          </button>
          <nav
            aria-label="Ruta actual"
            class="flex min-w-0 items-center gap-2 text-xs"
          >
            <span class="hidden truncate text-muted sm:inline">{{ companyName }}</span>
            <span class="hidden text-faint sm:inline">/</span>
            <span class="truncate font-medium text-ink">{{ pageTitle }}</span>
          </nav>
        </div>

        <div class="flex items-center gap-2.5">
          <AppStatusBadge :status="health.status" />
          <AppThemeToggle />
        </div>
      </header>

      <main
        id="contenido"
        class="flex-1 overflow-y-auto mx-auto w-full max-w-7xl space-y-6 p-4 lg:p-8"
      >
        <slot />
      </main>
    </div>
  </div>
</template>
