<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';

import { useRoute, useRouter } from 'vue-router';

import AppShell from '@/layouts/AppShell.vue';
import AppPage from '@/components/layout/AppPage.vue';
import CompanyTable from '@/components/companies/CompanyTable.vue';
import CompanyFormModal from '@/components/companies/CompanyFormModal.vue';
import CompanyArchiveModal from '@/components/companies/CompanyArchiveModal.vue';
import { useAuthStore } from '@/stores/auth';
import { useCompanyStore, type Company } from '@/stores/company';

const router = useRouter();
const route = useRoute();
const companyStore = useCompanyStore();
const authStore = useAuthStore();

const isOnboarding = computed(() => route.query.onboarding === '1');

const formOpen = ref(false);
const editingCompany = ref<Company | null>(null);
const archiveOpen = ref(false);
const archivingCompany = ref<Company | null>(null);

const canManage = computed(() => {
    const role = authStore.user?.memberships?.[0]?.role ?? '';
    return role === 'owner' || role === 'accountant';
});

onMounted(() => {
    if (!companyStore.loaded) {
        void companyStore.fetchCompanies();
    }

    if (isOnboarding.value && canManage.value && !companyStore.hasCompany) {
        openCreate();
    }
});

function openCreate(): void {
    editingCompany.value = null;
    formOpen.value = true;
}

function openEdit(company: Company): void {
    editingCompany.value = company;
    formOpen.value = true;
}

function openArchive(company: Company): void {
    archivingCompany.value = company;
    archiveOpen.value = true;
}

function onSaved(): void {
    editingCompany.value = null;
}

function onArchived(): void {
    archivingCompany.value = null;
}

function goToSync(): void {
    void router.push({ name: 'sync' });
}
</script>

<template>
  <AppShell>
    <AppPage
      title="Contribuyentes"
      description="Empresas del estudio: datos tributarios, régimen y estado."
    >
      <template #actions>
        <UButton
          v-if="canManage"
          color="primary"
          icon="i-lucide-plus"
          label="Registrar contribuyente"
          @click="openCreate"
        />
      </template>

      <div class="space-y-4">
        <UAlert
          v-if="isOnboarding && !companyStore.hasCompany"
          color="info"
          variant="soft"
          icon="i-lucide-rocket"
          title="Primer contribuyente"
          description="Registra tu primer contribuyente para continuar con la sincronización y la clasificación."
          class="mb-2"
        />

        <div
          v-if="!companyStore.loading && !companyStore.hasCompany && !companyStore.requiresTwoFactorSetup"
          class="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-default bg-elevated/40 px-6 py-12 text-center"
        >
          <UIcon
            name="i-lucide-building-2"
            class="size-10 text-dimmed"
          />
          <div>
            <p class="text-base font-medium text-highlighted">
              Aún no hay contribuyentes
            </p>
            <p class="mt-1 text-sm text-muted">
              Registra el primer RUC para sincronizar comprobantes y clasificar.
            </p>
          </div>
          <UButton
            v-if="canManage"
            color="primary"
            icon="i-lucide-plus"
            label="Registrar contribuyente"
            @click="openCreate"
          />
          <p
            v-else
            class="text-xs text-dimmed"
          >
            Pide a un owner o contador que registre el primer contribuyente.
          </p>
        </div>

        <UAlert
          v-else-if="companyStore.requiresTwoFactorSetup"
          color="warning"
          variant="soft"
          title="Configura la verificación en dos pasos"
          description="Para operar con contribuyentes debes activar 2FA en tu cuenta."
          icon="i-lucide-shield-alert"
        >
          <template #actions>
            <UButton
              color="warning"
              variant="soft"
              size="xs"
              to="/two-factor/setup"
            >
              Configurar 2FA
            </UButton>
          </template>
        </UAlert>

        <div
          v-else-if="companyStore.error"
          class="rounded-md border border-error/40 bg-error/10 px-4 py-3 text-sm text-error"
        >
          {{ companyStore.error }}
          <UButton
            color="error"
            variant="ghost"
            size="xs"
            class="ml-2"
            @click="companyStore.fetchCompanies()"
          >
            Reintentar
          </UButton>
        </div>

        <CompanyTable
          v-else
          :companies="companyStore.companies"
          :loading="companyStore.loading"
          :can-manage="canManage"
          :can-archive="canManage"
          @edit="openEdit"
          @archive="openArchive"
        />

        <div
          v-if="companyStore.hasCompany && !companyStore.loading"
          class="flex items-center justify-end gap-2 text-xs text-muted"
        >
          <span>Empresa activa: <span class="font-medium text-highlighted">{{ companyStore.activeCompany?.trade_name || companyStore.activeCompany?.legal_name }}</span></span>
          <UButton
            color="neutral"
            variant="ghost"
            size="xs"
            icon="i-lucide-refresh-cw"
            label="Ir a sincronización"
            @click="goToSync"
          />
        </div>
      </div>

      <CompanyFormModal
        v-model:open="formOpen"
        :company="editingCompany"
        @saved="onSaved"
      />

      <CompanyArchiveModal
        v-model:open="archiveOpen"
        :company="archivingCompany"
        @archived="onArchived"
      />
    </AppPage>
  </AppShell>
</template>
