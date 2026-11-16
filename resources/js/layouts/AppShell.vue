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
  <div class="flex min-h-screen bg-canvas text-ink">
    <a
      href="#contenido"
      class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-control focus:bg-accent focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-accent-fg"
    >
      Saltar al contenido
    </a>

    <div class="hidden lg:block">
      <AppSidebar class="sticky top-0 h-screen" />
    </div>

    <USlideover
      v-model:open="mobileOpen"
      side="left"
      :ui="{ content: 'w-64 max-w-[16rem] p-0', header: 'hidden', body: 'p-0' }"
    >
      <template #body>
        <AppSidebar />
      </template>
    </USlideover>

    <div class="flex min-w-0 flex-1 flex-col">
      <header class="sticky top-0 z-20 flex h-14 items-center justify-between gap-3 border-b border-line bg-surface/80 px-4 backdrop-blur-md lg:px-8">
        <div class="flex min-w-0 items-center gap-3">
          <UButton
            class="lg:hidden"
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-menu"
            aria-label="Abrir menú"
            @click="mobileOpen = true"
          />
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
        class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 lg:p-8"
      >
        <slot />
      </main>
    </div>
  </div>
</template>
