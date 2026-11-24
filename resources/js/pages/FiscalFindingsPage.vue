<script setup lang="ts">
import type { FiscalFinding } from '@/stores/fiscalFindings';
import type { FindingAction } from '@/lib/fiscalFindings';

import { computed, onMounted, ref, watch } from 'vue';

import { useToast } from '@nuxt/ui/composables/useToast';
import AppShell from '@/layouts/AppShell.vue';
import AppPage from '@/components/layout/AppPage.vue';
import CompanyRequiredEmptyState from '@/components/companies/CompanyRequiredEmptyState.vue';
import FiscalFindingFilters from '@/components/fiscal-findings/FiscalFindingFilters.vue';
import FiscalFindingSummaryCards from '@/components/fiscal-findings/FiscalFindingSummaryCards.vue';
import FiscalFindingTable from '@/components/fiscal-findings/FiscalFindingTable.vue';
import { useAuthStore } from '@/stores/auth';
import { useCompanyStore } from '@/stores/company';
import { useFiscalFindingStore } from '@/stores/fiscalFindings';

import FiscalFindingActionModal from '@/components/fiscal-findings/FiscalFindingActionModal.vue';
import FiscalFindingDetailDrawer from '@/components/fiscal-findings/FiscalFindingDetailDrawer.vue';

const authStore = useAuthStore();
const companyStore = useCompanyStore();
const store = useFiscalFindingStore();
const toast = useToast();

const detailOpen = ref(false);
const detailFinding = ref<FiscalFinding | null>(null);
const pendingManage = ref<FiscalFinding | null>(null);
const actionOpen = ref(false);
const actionFinding = ref<FiscalFinding | null>(null);
const actionType = ref<FindingAction>('acknowledged');

const canReview = computed(() => {
    const role = authStore.user?.memberships?.[0]?.role ?? '';

    return role === 'owner' || role === 'accountant' || role === 'assistant';
});

const targetCompanyId = computed(() => {
    if (store.filters.companyId !== 'all') {
        return store.filters.companyId;
    }

    return companyStore.activeCompany?.id ?? null;
});

const hasActiveFilters = computed(
    () =>
        store.filters.status !== 'open'
        || store.filters.severity !== 'all'
        || store.filters.code !== 'all'
        || store.filters.search.trim() !== '',
);

function openDetail(item: FiscalFinding): void {
    detailFinding.value = item;
    detailOpen.value = true;
}

function openManage(item: FiscalFinding): void {
    if (detailOpen.value) {
        pendingManage.value = item;
        detailOpen.value = false;
    } else {
        actionFinding.value = item;
        actionType.value = 'acknowledged';
        actionOpen.value = true;
    }
}

async function confirmAction(action: FindingAction, note: string): Promise<void> {
    if (!actionFinding.value) return;

    try {
        const updated = await store.updateFinding(actionFinding.value.id, action, note);

        toast.add({ title: 'Hallazgo actualizado', color: 'success' });

        if (detailFinding.value?.id === updated.id) {
            detailFinding.value = updated;
        }

        actionOpen.value = false;
        await store.fetchFindings(store.meta?.current_page ?? 1);
    } catch (caught: unknown) {
        toast.add({
            title: 'No se pudo actualizar el hallazgo',
            description: caught instanceof Error ? caught.message : 'Intenta de nuevo.',
            color: 'error',
        });
    }
}

async function detectNow(): Promise<void> {
    const companyId = targetCompanyId.value;

    if (companyId === null) return;

    try {
        const result = await store.detect(companyId);

        toast.add({
            title: 'Detección completada',
            description: `${result.created} nuevos · ${result.updated} actualizados · ${result.resolved} resueltos`,
            color: 'success',
        });

        await store.fetchFindings(1);
    } catch (caught: unknown) {
        toast.add({
            title: 'No se pudo ejecutar la detección',
            description: caught instanceof Error ? caught.message : 'Intenta de nuevo.',
            color: 'error',
        });
    }
}

onMounted(() => {
    if (!companyStore.loaded) {
        void companyStore.fetchCompanies();
    }

    if (companyStore.hasCompany) {
        void store.fetchFindings();
    }
});

watch(detailOpen, (isOpen) => {
    if (!isOpen && pendingManage.value) {
        // Esperar a que termine la animación de salida del drawer antes de
        // abrir el modal, para que no quede una sombra encima.
        window.setTimeout(() => {
            const item = pendingManage.value;
            pendingManage.value = null;
            if (item) {
                actionFinding.value = item;
                actionType.value = 'acknowledged';
                actionOpen.value = true;
            }
        }, 250);
    }
});
</script>

<template>
  <AppShell>
    <AppPage
      title="Hallazgos fiscales"
      description="Riesgos detectados sobre los comprobantes: totales inconsistentes, RUC inválido, empresas fantasma y secuenciales duplicados."
    >
      <template #actions>
        <UButton
          v-if="canReview"
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-radar"
          label="Detectar ahora"
          class="h-8"
          :loading="store.detecting"
          :disabled="!companyStore.hasCompany"
          @click="detectNow"
        />
      </template>

      <CompanyRequiredEmptyState
        v-if="!companyStore.loading && !companyStore.hasCompany"
        description="Necesitas una empresa activa para revisar hallazgos fiscales."
      />

      <template v-else>
        <FiscalFindingSummaryCards :counts="store.counts" />

        <FiscalFindingFilters
          :company-id="store.filters.companyId"
          :status="store.filters.status"
          :severity="store.filters.severity"
          :code="store.filters.code"
          :search="store.filters.search"
          @update:company-id="(value: string) => (store.filters.companyId = value)"
          @update:status="(value: string) => (store.filters.status = value)"
          @update:severity="(value: string) => (store.filters.severity = value)"
          @update:code="(value: string) => (store.filters.code = value)"
          @update:search="(value: string) => (store.filters.search = value)"
          @apply="store.fetchFindings(1)"
        />

        <div
          v-if="store.loading"
          class="flex items-center justify-center py-12 text-muted"
        >
          Cargando hallazgos…
        </div>

        <div
          v-else-if="store.error"
          class="flex flex-col items-center gap-3 py-12"
        >
          <p class="text-sm text-error">
            {{ store.error }}
          </p>
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            label="Reintentar"
            @click="store.fetchFindings(1)"
          />
        </div>

        <div
          v-else-if="store.items.length === 0"
          class="flex flex-col items-center gap-2 py-12 text-muted"
        >
          <UIcon
            name="i-lucide-shield-check"
            class="size-8"
          />
          <p class="text-sm">
            {{ hasActiveFilters ? 'No hay hallazgos que coincidan con los filtros.' : 'No hay hallazgos abiertos para este contribuyente.' }}
          </p>
          <p
            v-if="!hasActiveFilters && canReview"
            class="text-xs text-dimmed"
          >
            Ejecuta «Detectar ahora» para revisar los comprobantes del período.
          </p>
        </div>

        <FiscalFindingTable
          v-else
          :items="store.items"
          :actions-enabled="canReview"
          @view="openDetail"
          @manage="openManage"
        />

        <div
          v-if="store.meta && store.meta.last_page > 1"
          class="flex items-center justify-between text-sm text-muted"
        >
          <span>Página {{ store.meta.current_page }} de {{ store.meta.last_page }} ({{ store.meta.total }} hallazgos)</span>
          <div class="flex gap-2">
            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              label="Anterior"
              :disabled="store.meta.current_page <= 1"
              @click="store.fetchFindings((store.meta?.current_page ?? 1) - 1)"
            />
            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              label="Siguiente"
              :disabled="store.meta.current_page >= store.meta.last_page"
              @click="store.fetchFindings((store.meta?.current_page ?? 1) + 1)"
            />
          </div>
        </div>

        <FiscalFindingDetailDrawer
          v-if="detailOpen"
          v-model:open="detailOpen"
          :finding="detailFinding"
          :actions-enabled="canReview"
          @manage="openManage"
        />

        <FiscalFindingActionModal
          v-model:open="actionOpen"
          :finding="actionFinding"
          :action="actionType"
          :saving="store.saving"
          @confirm="confirmAction"
        />
      </template>
    </AppPage>
  </AppShell>
</template>
