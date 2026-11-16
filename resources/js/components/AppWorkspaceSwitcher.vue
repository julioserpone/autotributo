<script setup lang="ts">
import { computed, ref } from 'vue';

import { storeToRefs } from 'pinia';

import { useCompanyStore } from '@/stores/company';

const companyStore = useCompanyStore();
const { companies, activeCompany } = storeToRefs(companyStore);

const open = ref(false);

const displayName = computed(() => activeCompany.value?.trade_name || activeCompany.value?.legal_name || 'Sin empresa');

function select(id: string): void {
    companyStore.setActive(id);
    open.value = false;
}
</script>

<template>
  <UPopover
    v-model:open="open"
    :portal="false"
    :content="{ side: 'bottom', sideOffset: 8, align: 'start' }"
  >
    <button
      type="button"
      class="group flex w-full items-center justify-between gap-2 rounded-md px-2 py-1.5 text-left transition hover:bg-elevated"
    >
      <span class="flex min-w-0 items-center gap-2.5">
        <span class="grid size-6 shrink-0 place-items-center rounded-md bg-brand text-xs font-bold text-white">
          A
        </span>
        <span class="min-w-0">
          <span class="block truncate text-xs font-semibold text-ink">Autotributo</span>
          <span class="block truncate text-2xs text-muted">{{ displayName }}</span>
        </span>
      </span>
      <UIcon
        name="i-lucide-chevrons-up-down"
        class="size-3.5 shrink-0 text-faint transition group-hover:text-ink"
      />
    </button>

    <template #content>
      <div class="w-64 p-1">
        <p class="px-2 py-1.5 text-2xs font-medium uppercase tracking-wider text-faint">
          Empresas
        </p>
        <button
          v-for="company in companies"
          :key="company.id"
          type="button"
          class="flex w-full items-center justify-between gap-2 rounded-md px-2 py-1.5 text-left transition hover:bg-elevated"
          @click="select(company.id)"
        >
          <span class="min-w-0">
            <span class="block truncate text-xs font-medium text-ink">
              {{ company.trade_name || company.legal_name }}
            </span>
            <span class="block truncate font-mono text-2xs text-faint">
              {{ company.ruc }}
            </span>
          </span>
          <UIcon
            v-if="company.id === activeCompany?.id"
            name="i-lucide-check"
            class="size-3.5 shrink-0 text-ink"
          />
        </button>

        <p
          v-if="companies.length === 0"
          class="px-2 py-2 text-xs text-muted"
        >
          No hay empresas registradas.
        </p>
      </div>
    </template>
  </UPopover>
</template>
