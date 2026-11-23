<script setup lang="ts">
import { computed, defineAsyncComponent, onMounted, onUnmounted, ref, watch } from 'vue';

import type { ClassificationApi } from '@/stores/classifications';

import { getJson } from '@/lib/api';
import AppShell from '@/layouts/AppShell.vue';
import AppPage from '@/components/layout/AppPage.vue';
import CompanyRequiredEmptyState from '@/components/companies/CompanyRequiredEmptyState.vue';
import ClassificationBulkBar from '@/components/classifications/ClassificationBulkBar.vue';
const ClassificationDetailModal = defineAsyncComponent(() => import('@/components/classifications/ClassificationDetailModal.vue'));
const ClassificationRejectModal = defineAsyncComponent(() => import('@/components/classifications/ClassificationRejectModal.vue'));
import ClassificationFilters from '@/components/classifications/ClassificationFilters.vue';
const ClassificationHelpModal = defineAsyncComponent(() => import('@/components/classifications/ClassificationHelpModal.vue'));
import ClassificationTable from '@/components/classifications/ClassificationTable.vue';
const CreateRuleModal = defineAsyncComponent(() => import('@/components/classifications/CreateRuleModal.vue'));
import { useCompanyStore } from '@/stores/company';
import { useClassificationStore } from '@/stores/classifications';

const companyStore = useCompanyStore();
const store = useClassificationStore();

const focusIndex = ref(0);
const detailOpen = ref(false);
const detailItem = ref<ClassificationApi | null>(null);
const rejectConfirmOpen = ref(false);
const pendingRejectId = ref<number | null>(null);
const helpOpen = ref(false);
const createRuleOpen = ref(false);
const createRuleSource = ref<number | null>(null);
const createRuleCategory = ref<number>(0);
const createRulePattern = ref('');
const bulkCategory = ref<number | null>(null);

const expenseCategories = ref<{ id: number; code: string; name: string }[]>([]);

async function loadCategories(): Promise<void> {
    try {
        const response = await getJson<{ data: { id: number; code: string; name: string }[] }>('/api/v1/expense-categories');
        expenseCategories.value = response.data ?? [];
    } catch {
        expenseCategories.value = [];
    }
}

const categoryOptions = computed(() => expenseCategories.value.map((category) => ({ label: category.name, value: category.id })));

const focusedItem = computed(() => store.items[focusIndex.value] ?? null);

function moveFocus(delta: number): void {
    if (store.items.length === 0) return;
    focusIndex.value = Math.max(0, Math.min(store.items.length - 1, focusIndex.value + delta));
}

function openDetail(item: ClassificationApi): void {
    detailItem.value = item;
    detailOpen.value = true;
}

function openCreateRule(item: ClassificationApi): void {
    createRuleSource.value = item.id;
    createRuleCategory.value = item.expense_category?.id ?? 0;
    createRulePattern.value = item.item?.description ?? '';
    createRuleOpen.value = true;
}

function requestReject(id: number): void {
    pendingRejectId.value = id;
    rejectConfirmOpen.value = true;
}

async function confirmReject(): Promise<void> {
    if (pendingRejectId.value === null) return;
    await store.reject(pendingRejectId.value);
    pendingRejectId.value = null;
    rejectConfirmOpen.value = false;
    await store.fetchQueue(store.meta?.current_page ?? 1);
}

async function acceptItem(id: number, categoryId: number | undefined): Promise<void> {
    await store.accept(id, categoryId);
    await store.fetchQueue(store.meta?.current_page ?? 1);
}

async function revertItem(item: ClassificationApi): Promise<void> {
    await store.revert(item.id);
    await store.fetchQueue(store.meta?.current_page ?? 1);
}

function onKeyDown(event: KeyboardEvent): void {
    const target = event.target as HTMLElement | null;
    if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT')) {
        return;
    }

    switch (event.key) {
        case 'j':
        case 'ArrowDown':
            event.preventDefault();
            moveFocus(1);
            break;
        case 'k':
        case 'ArrowUp':
            event.preventDefault();
            moveFocus(-1);
            break;
        case ' ':
            event.preventDefault();
            if (focusedItem.value) store.toggleSelection(focusedItem.value.id);
            break;
        case 'a':
            event.preventDefault();
            if (focusedItem.value) void store.accept(focusedItem.value.id, focusedItem.value.expense_category?.id);
            break;
        case 'r':
            event.preventDefault();
            if (focusedItem.value) void store.reject(focusedItem.value.id);
            break;
        case 'Enter':
            event.preventDefault();
            if (focusedItem.value) openCreateRule(focusedItem.value);
            break;
        default:
            break;
    }
}

async function bulkAccept(): Promise<void> {
    const ids = Array.from(store.selectedIds);
    if (ids.length === 0 || bulkCategory.value === null) return;
    await store.bulkReview(ids, 'accept', bulkCategory.value ?? undefined);
}

const bulkRejectConfirmOpen = ref(false);

async function bulkReject(): Promise<void> {
    const ids = Array.from(store.selectedIds);
    if (ids.length === 0) return;
    bulkRejectConfirmOpen.value = true;
}

async function confirmBulkReject(): Promise<void> {
    const ids = Array.from(store.selectedIds);
    await store.bulkReview(ids, 'reject');
    bulkRejectConfirmOpen.value = false;
}

onMounted(() => {
    if (!companyStore.loaded) {
        void companyStore.fetchCompanies();
    }

    void loadCategories();

    if (companyStore.hasCompany) {
        void store.fetchQueue();
    }

    window.addEventListener('keydown', onKeyDown);
});

onUnmounted(() => {
    window.removeEventListener('keydown', onKeyDown);
});

watch(() => store.items, (items) => {
    if (focusIndex.value >= items.length) {
        focusIndex.value = Math.max(0, items.length - 1);
    }
});
</script>

<template>
  <AppShell>
    <AppPage
      title="Revisión de clasificación"
      description="Cola de ítems clasificados por reglas o IA que requieren decisión humana."
    >
      <CompanyRequiredEmptyState
        v-if="!companyStore.loading && !companyStore.hasCompany"
        description="Necesitas una empresa activa para revisar clasificaciones."
      />

      <template v-else>
        <ClassificationFilters
          :status="store.filters.status"
          :method="store.filters.method"
          :company-id="store.filters.companyId"
          :search="store.filters.search"
          @update:status="(value: string) => (store.filters.status = value)"
          @update:method="(value: string) => (store.filters.method = value)"
          @update:company-id="(value: string) => (store.filters.companyId = value)"
          @update:search="(value: string) => (store.filters.search = value)"
          @apply="store.fetchQueue(1)"
          @help="helpOpen = true"
        />

        <ClassificationBulkBar
          v-if="store.selectedCount > 0"
          :count="store.selectedCount"
          :categories="categoryOptions"
          :category="bulkCategory"
          @update:category="(value: number) => (bulkCategory = value)"
          @accept="bulkAccept"
          @reject="bulkReject"
          @clear="store.clearSelection()"
        />

        <div
          v-if="store.loading"
          class="flex items-center justify-center py-12 text-muted"
        >
          Cargando cola…
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
            @click="store.fetchQueue(1)"
          />
        </div>
        <div
          v-else-if="store.items.length === 0"
          class="flex flex-col items-center gap-2 py-12 text-muted"
        >
          <UIcon
            name="i-lucide-inbox"
            class="size-8"
          />
          <p class="text-sm">
            No hay clasificaciones pendientes de revisión.
          </p>
        </div>

        <ClassificationTable
          v-else
          :items="store.items"
          :focus-index="focusIndex"
          :selected-ids="store.selectedIds"
          @update:focus-index="(value: number) => (focusIndex = value)"
          @toggle-selection="store.toggleSelection"
          @accept="acceptItem"
          @request-reject="requestReject"
          @revert="revertItem"
          @view="openDetail"
          @create-rule="openCreateRule"
        />

        <div
          v-if="store.meta && store.meta.last_page > 1"
          class="flex items-center justify-between text-sm text-muted"
        >
          <span>Página {{ store.meta.current_page }} de {{ store.meta.last_page }} ({{ store.meta.total }} ítems)</span>
          <div class="flex gap-2">
            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              label="Anterior"
              :disabled="store.meta.current_page <= 1"
              @click="store.fetchQueue((store.meta?.current_page ?? 1) - 1)"
            />
            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              label="Siguiente"
              :disabled="store.meta.current_page >= store.meta.last_page"
              @click="store.fetchQueue((store.meta?.current_page ?? 1) + 1)"
            />
          </div>
        </div>

        <p class="text-xs text-muted">
          Atajos: <kbd>j</kbd>/<kbd>k</kbd> navegar · <kbd>espacio</kbd> seleccionar · <kbd>a</kbd> aceptar · <kbd>r</kbd> rechazar · <kbd>Enter</kbd> crear regla.
        </p>

        <ClassificationDetailModal
          v-model:open="detailOpen"
          :item="detailItem"
        />

        <ClassificationRejectModal
          v-model:open="rejectConfirmOpen"
          @confirm="confirmReject"
        />

        <ClassificationRejectModal
          v-model:open="bulkRejectConfirmOpen"
          title="¿Rechazar seleccionados?"
          description="Los ítems seleccionados volverán a estado pendiente."
          @confirm="confirmBulkReject"
        />

        <ClassificationHelpModal v-model:open="helpOpen" />

        <CreateRuleModal
          v-model:open="createRuleOpen"
          :source-id="createRuleSource"
          :category="createRuleCategory"
          :pattern="createRulePattern"
          :categories="categoryOptions"
          @update:category="(value: number) => (createRuleCategory = value)"
          @update:pattern="(value: string) => (createRulePattern = value)"
          @created="store.fetchQueue(store.meta?.current_page ?? 1)"
        />
      </template>
    </AppPage>
  </AppShell>
</template>