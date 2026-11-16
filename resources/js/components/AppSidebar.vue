<script setup lang="ts">
import { computed } from 'vue';
import { useRouter, useRoute } from 'vue-router';

import AppWorkspaceSwitcher from '@/components/AppWorkspaceSwitcher.vue';
import { useAuthStore } from '@/stores/auth';
import { useCompanyStore } from '@/stores/company';

const auth = useAuthStore();
const companyStore = useCompanyStore();
const route = useRoute();
const router = useRouter();

const initials = computed(() => {
    const name = auth.user?.name ?? '';
    const parts = name.trim().split(/\s+/).filter(Boolean);
    return parts.slice(0, 2).map((part) => part.charAt(0).toUpperCase()).join('') || 'U';
});

interface NavItem {
    label: string;
    icon: string;
    route?: string;
    soon?: boolean;
}

const primaryNav: NavItem[] = [
    { label: 'Dashboard', icon: 'i-lucide-layout-dashboard', route: 'dashboard' },
    { label: 'Comprobantes SRI', icon: 'i-lucide-file-text', soon: true },
    { label: 'Declaraciones (104 / ATS)', icon: 'i-lucide-chart-column', soon: true },
    { label: 'Contribuyentes', icon: 'i-lucide-users', soon: true },
];

const systemNav: NavItem[] = [
    { label: 'Seguridad (2FA)', icon: 'i-lucide-shield-check', route: 'two-factor-setup' },
    { label: 'Firma electrónica (.p12)', icon: 'i-lucide-key-round', soon: true },
    { label: 'Configuración', icon: 'i-lucide-settings', soon: true },
];

function isActive(item: NavItem): boolean {
    return item.route !== undefined && route.name === item.route;
}

async function logout(): Promise<void> {
    await auth.logout();
    await router.push({ name: 'login' });
}
</script>

<template>
  <aside class="flex h-full w-64 shrink-0 flex-col border-r border-line bg-surface">
    <div class="flex h-12 items-center border-b border-line px-3">
      <AppWorkspaceSwitcher />
    </div>

    <div class="flex-1 overflow-y-auto p-3">
      <div class="space-y-5">
        <section>
          <p class="mb-1.5 px-2.5 text-2xs font-medium uppercase tracking-wider text-faint">
            Plataforma tributaria
          </p>
          <nav class="space-y-0.5">
            <RouterLink
              v-for="item in primaryNav"
              :key="item.label"
              v-slot="{ navigate }"
              :to="item.route ? { name: item.route as string } : '/'"
              custom
            >
              <button
                v-if="item.soon"
                type="button"
                class="nav-item flex w-full items-center gap-2.5 rounded-md px-2.5 py-1.5 text-xs font-medium text-muted transition"
                disabled
                :title="`${item.label} · próximamente`"
              >
                <UIcon
                  :name="item.icon"
                  class="size-4 text-faint"
                />
                <span class="truncate">{{ item.label }}</span>
              </button>
              <a
                v-else
                class="nav-item flex items-center gap-2.5 rounded-md px-2.5 py-1.5 text-xs font-medium transition"
                :class="isActive(item)
                  ? 'border border-line bg-elevated text-ink'
                  : 'text-muted hover:bg-elevated hover:text-ink'"
                @click="navigate"
              >
                <UIcon
                  :name="item.icon"
                  class="size-4"
                  :class="isActive(item) ? 'text-ink' : 'text-faint'"
                />
                <span class="truncate">{{ item.label }}</span>
              </a>
            </RouterLink>
          </nav>
        </section>

        <section>
          <p class="mb-1.5 px-2.5 text-2xs font-medium uppercase tracking-wider text-faint">
            Sistema
          </p>
          <nav class="space-y-0.5">
            <RouterLink
              v-for="item in systemNav"
              :key="item.label"
              v-slot="{ navigate }"
              :to="item.route ? { name: item.route as string } : '/'"
              custom
            >
              <button
                v-if="item.soon"
                type="button"
                class="nav-item flex w-full items-center gap-2.5 rounded-md px-2.5 py-1.5 text-xs font-medium text-muted transition"
                disabled
                :title="`${item.label} · próximamente`"
              >
                <UIcon
                  :name="item.icon"
                  class="size-4 text-faint"
                />
                <span class="truncate">{{ item.label }}</span>
              </button>
              <a
                v-else
                class="nav-item flex items-center gap-2.5 rounded-md px-2.5 py-1.5 text-xs font-medium transition"
                :class="isActive(item)
                  ? 'border border-line bg-elevated text-ink'
                  : 'text-muted hover:bg-elevated hover:text-ink'"
                @click="navigate"
              >
                <UIcon
                  :name="item.icon"
                  class="size-4"
                  :class="isActive(item) ? 'text-ink' : 'text-faint'"
                />
                <span class="truncate">{{ item.label }}</span>
              </a>
            </RouterLink>
          </nav>
        </section>
      </div>
    </div>

    <div class="border-t border-line p-3">
      <div class="flex items-center justify-between gap-2 rounded-md px-2 py-1.5">
        <div class="flex min-w-0 items-center gap-2.5">
          <span class="grid size-7 shrink-0 place-items-center rounded-full border border-line bg-elevated text-xs font-medium text-ink">
            {{ initials }}
          </span>
          <div class="min-w-0">
            <p class="truncate text-xs font-medium text-ink">
              {{ auth.user?.name ?? 'Sesión activa' }}
            </p>
            <p class="truncate font-mono text-2xs text-faint">
              {{ companyStore.activeCompany?.ruc ?? auth.user?.email }}
            </p>
          </div>
        </div>
        <UButton
          color="neutral"
          variant="ghost"
          size="xs"
          icon="i-lucide-log-out"
          aria-label="Cerrar sesión"
          @click="logout"
        />
      </div>
    </div>
  </aside>
</template>
