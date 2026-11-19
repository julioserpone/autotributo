<script setup lang="ts">
import { computed } from 'vue';
import type { DropdownMenuItem } from '@nuxt/ui';

import { useCompanyStore } from '@/stores/company';

const props = defineProps<{
    collapsed?: boolean;
}>();

const companyStore = useCompanyStore();

const activeName = computed(
    () => companyStore.activeCompany?.trade_name || companyStore.activeCompany?.legal_name || 'Sin empresa',
);

const items = computed<DropdownMenuItem[][]>(() => [
    companyStore.companies.map((company) => ({
        label: company.trade_name || company.legal_name,
        description: company.ruc,
        icon: company.id === companyStore.activeId ? 'i-lucide-check' : 'i-lucide-building-2',
        onSelect: () => companyStore.setActive(company.id),
    })),
    [
        {
            label: 'Gestionar empresas',
            icon: 'i-lucide-settings-2',
            to: { name: 'dashboard' },
            disabled: true,
        },
    ],
]);

const triggerProps = computed(() => ({
    ...(props.collapsed
        ? {}
        : { label: activeName.value, trailingIcon: 'i-lucide-chevrons-up-down' }),
}));
</script>

<template>
  <UDropdownMenu
    :items="items"
    :content="{ align: 'start', collisionPadding: 12 }"
    :ui="{ content: collapsed ? 'w-56' : 'w-(--reka-dropdown-menu-trigger-width)' }"
  >
    <UButton
      v-bind="triggerProps"
      color="neutral"
      variant="ghost"
      block
      :square="collapsed"
      class="data-[state=open]:bg-elevated"
      :class="[!collapsed && 'py-2']"
      :ui="{ trailingIcon: 'text-dimmed' }"
    >
      <template #leading>
        <span class="grid size-6 shrink-0 place-items-center rounded-full bg-primary text-2xs font-bold text-inverted">
          A
        </span>
      </template>
    </UButton>
  </UDropdownMenu>
</template>
