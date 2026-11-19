<script setup lang="ts">
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import type { DropdownMenuItem } from '@nuxt/ui';

import { useAuthStore } from '@/stores/auth';
import { useCompanyStore } from '@/stores/company';

const props = defineProps<{
    collapsed?: boolean;
}>();

const auth = useAuthStore();
const companyStore = useCompanyStore();
const router = useRouter();
const initials = computed(() => {
    const parts = (auth.user?.name ?? '').trim().split(/\s+/).filter(Boolean);
    return parts.slice(0, 2).map((part) => part.charAt(0).toUpperCase()).join('') || 'U';
});

const subtitle = computed(() => companyStore.activeCompany?.ruc ?? auth.user?.email ?? '');

const triggerProps = computed(() => ({
    ...(props.collapsed
        ? {}
        : { label: auth.user?.name ?? 'Sesión activa', trailingIcon: 'i-lucide-chevrons-up-down' }),
}));

const items = computed<DropdownMenuItem[][]>(() => [
    [
        {
            type: 'label',
            label: auth.user?.name ?? 'Sesión activa',
            avatar: { alt: auth.user?.name ?? 'Usuario' },
        },
    ],
    [
        {
            label: 'Seguridad',
            icon: 'i-lucide-shield-check',
            to: { name: 'two-factor-setup' },
        },
    ],
    [
        {
            label: 'Cerrar sesión',
            icon: 'i-lucide-log-out',
            onSelect: () => {
                void logout();
            },
        },
    ],
]);

async function logout(): Promise<void> {
    await auth.logout();
    await router.push({ name: 'login' });
}
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
      :ui="{ trailingIcon: 'text-dimmed' }"
    >
      <template #leading>
        <span class="grid size-7 shrink-0 place-items-center rounded-full bg-elevated text-2xs font-semibold text-highlighted ring ring-default">
          {{ initials }}
        </span>
      </template>
      <template
        v-if="!collapsed && subtitle"
        #default
      >
        <span class="flex min-w-0 flex-col items-start">
          <span class="truncate text-sm">{{ auth.user?.name ?? 'Sesión activa' }}</span>
          <span class="truncate font-mono text-2xs font-normal text-dimmed">{{ subtitle }}</span>
        </span>
      </template>
    </UButton>
  </UDropdownMenu>
</template>
