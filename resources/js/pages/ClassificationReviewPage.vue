<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

import type { ClassificationApi } from '@/stores/classifications';
import AppShell from '@/layouts/AppShell.vue';
import AppPage from '@/components/layout/AppPage.vue';
import { useCompanyStore } from '@/stores/company';
import { useClassificationStore, confidenceColor, methodLabels } from '@/stores/classifications';

const companyStore = useCompanyStore();
const store = useClassificationStore();

const focusIndex = ref(0);
const createRuleOpen = ref(false);
const createRuleSource = ref<number | null>(null);
const createRuleCategory = ref<number>(0);
const createRulePattern = ref('');
const createRuleMatchType = ref('contains');
const bulkCategory = ref<number>(0);

const money = (cents: number): string =>
    new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(cents / 100);

const expenseCategories = ref<{ id: number; code: string; name: string }[]>([]);

async function loadCategories(): Promise<void> {
    try {
        const response = await (await fetch('/api/v1/expense-categories', { credentials: 'same-origin', headers: { Accept: 'application/json' } })).json() as { data: { id: number; code: string; name: string }[] };
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

function openCreateRule(item: ClassificationApi): void {
    createRuleSource.value = item.id;
    createRuleCategory.value = item.expense_category?.id ?? 0;
    createRulePattern.value = item.item?.description ?? '';
    createRuleOpen.value = true;
}

async function submitCreateRule(): Promise<void> {
    if (createRuleSource.value === null) return;
    try {
        await (await fetch('/api/v1/classification-rules', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '' },
            body: JSON.stringify({
                match_type: createRuleMatchType.value,
                pattern: createRulePattern.value,
                expense_category_id: createRuleCategory.value > 0 ? createRuleCategory.value : undefined,
                priority: 100,
                is_active: true,
            }),
        })).json();
        createRuleOpen.value = false;
    } catch {
        // Se conserva el diálogo abierto para reintentar.
    }
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
    if (ids.length === 0) return;
    await store.bulkReview(ids, 'accept', bulkCategory.value > 0 ? bulkCategory.value : undefined);
}

async function bulkReject(): Promise<void> {
    const ids = Array.from(store.selectedIds);
    if (ids.length === 0) return;
    await store.bulkReview(ids, 'reject');
}

onMounted(() => {
    if (!companyStore.loaded) {
        void companyStore.fetchCompanies();
    }
    void loadCategories();
    void store.fetchQueue();
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
      <!-- Filtros -->
      <section class="rounded-lg border border-default bg-elevated/50 p-4">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <UFormField
            label="Estado"
            name="status"
          >
            <USelect
              v-model="store.filters.status"
              :items="[
                { label: 'Necesita revisión', value: 'needs_review' },
                { label: 'Sugerida', value: 'suggested' },
                { label: 'Aceptada', value: 'accepted' },
                { label: 'Rechazada', value: 'rejected' },
                { label: 'Todos', value: 'all' },
              ]"
              color="neutral"
              variant="outline"
              size="md"
              class="w-full"
              @change="store.fetchQueue(1)"
            />
          </UFormField>

          <UFormField
            label="Método"
            name="method"
          >
            <USelect
              v-model="store.filters.method"
              :items="[
                { label: 'Todos', value: 'all' },
                { label: 'Regla', value: 'rule' },
                { label: 'Caché', value: 'cache' },
                { label: 'IA', value: 'llm' },
                { label: 'Manual', value: 'manual' },
              ]"
              color="neutral"
              variant="outline"
              size="md"
              class="w-full"
              @change="store.fetchQueue(1)"
            />
          </UFormField>

          <UFormField
            label="Empresa"
            name="company"
          >
            <USelect
              v-model="store.filters.companyId"
              :items="[
                { label: 'Todas', value: 'all' },
                ...companyStore.companies.map((c) => ({ label: c.trade_name || c.legal_name, value: c.id })),
              ]"
              color="neutral"
              variant="outline"
              size="md"
              class="w-full"
              @change="store.fetchQueue(1)"
            />
          </UFormField>

          <UFormField
            label="Buscar"
            name="search"
          >
            <UInput
              v-model="store.filters.search"
              placeholder="Descripción…"
              icon="i-lucide-search"
              color="neutral"
              variant="outline"
              size="md"
              class="w-full"
              @keyup.enter="store.fetchQueue(1)"
            />
          </UFormField>
        </div>
      </section>

      <!-- Barra de acciones en bloque -->
      <section
        v-if="store.selectedCount > 0"
        class="sticky top-0 z-10 flex flex-wrap items-center gap-3 rounded-lg border border-primary/40 bg-elevated p-3"
      >
        <span class="text-sm font-medium">{{ store.selectedCount }} seleccionados</span>
        <USelect
          v-model="bulkCategory"
          :items="categoryOptions"
          placeholder="Categoría…"
          color="neutral"
          variant="outline"
          size="sm"
          class="w-48"
        />
        <UButton
          color="primary"
          variant="solid"
          size="sm"
          label="Aceptar sugeridas"
          icon="i-lucide-check"
          @click="bulkAccept"
        />
        <UButton
          color="error"
          variant="soft"
          size="sm"
          label="Rechazar"
          icon="i-lucide-x"
          @click="bulkReject"
        />
        <UButton
          color="neutral"
          variant="ghost"
          size="sm"
          label="Limpiar"
          @click="store.clearSelection()"
        />
      </section>

      <!-- Estado de carga / vacío / error -->
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

      <!-- Cola -->
      <section
        v-else
        class="overflow-hidden rounded-lg border border-default"
      >
        <table class="w-full text-sm">
          <thead class="bg-elevated/60 text-left text-xs uppercase text-muted">
            <tr>
              <th class="w-10 px-3 py-2" />
              <th class="px-3 py-2">
                Comprobante / ítem
              </th>
              <th class="px-3 py-2">
                Categoría sugerida
              </th>
              <th class="px-3 py-2">
                Confianza
              </th>
              <th class="px-3 py-2">
                Método
              </th>
              <th class="px-3 py-2 text-right">
                Importe
              </th>
              <th class="px-3 py-2 text-right">
                Acciones
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(item, index) in store.items"
              :key="item.id"
              :class="[
                'border-t border-default transition-colors',
                index === focusIndex ? 'bg-primary/10' : 'hover:bg-elevated/40',
              ]"
              @click="focusIndex = index"
            >
              <td class="px-3 py-2">
                <input
                  type="checkbox"
                  :checked="store.selectedIds.has(item.id)"
                  class="size-4 rounded border-default"
                  @change="store.toggleSelection(item.id)"
                >
              </td>
              <td class="px-3 py-2">
                <div class="font-medium text-highlighted">
                  {{ item.item?.description ?? '—' }}
                </div>
                <div class="text-xs text-muted">
                  {{ item.fiscal_document?.issuer_name ?? '—' }} · {{ item.fiscal_document?.issue_date ?? '—' }}
                </div>
              </td>
              <td class="px-3 py-2">
                <span v-if="item.expense_category">{{ item.expense_category.name }}</span>
                <span
                  v-else
                  class="text-muted"
                >Sin categoría</span>
              </td>
              <td class="px-3 py-2">
                <UBadge
                  :color="confidenceColor(item.confidence)"
                  variant="soft"
                  size="xs"
                  :label="`${Math.round(item.confidence * 100)}%`"
                />
              </td>
              <td class="px-3 py-2 text-muted">
                {{ methodLabels[item.method] ?? item.method }}
              </td>
              <td class="px-3 py-2 text-right font-medium">
                {{ money(item.item?.total_cents ?? 0) }}
              </td>
              <td class="px-3 py-2 text-right">
                <div class="flex justify-end gap-1">
                  <UButton
                    color="success"
                    variant="soft"
                    size="xs"
                    icon="i-lucide-check"
                    label="Aceptar"
                    @click.stop="store.accept(item.id, item.expense_category?.id)"
                  />
                  <UButton
                    color="error"
                    variant="soft"
                    size="xs"
                    icon="i-lucide-x"
                    label="Rechazar"
                    @click.stop="store.reject(item.id)"
                  />
                  <UButton
                    color="neutral"
                    variant="ghost"
                    size="xs"
                    icon="i-lucide-wand-2"
                    label="Regla"
                    @click.stop="openCreateRule(item)"
                  />
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- Paginación -->
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

      <!-- Ayuda de teclado -->
      <p class="text-xs text-muted">
        Atajos: <kbd>j</kbd>/<kbd>k</kbd> navegar · <kbd>espacio</kbd> seleccionar · <kbd>a</kbd> aceptar · <kbd>r</kbd> rechazar · <kbd>Enter</kbd> crear regla.
      </p>

      <!-- Diálogo crear regla desde ítem -->
      <UModal
        v-model:open="createRuleOpen"
        title="Crear regla desde este ítem"
        description="La regla se aplicará a comprobantes futuros con el mismo patrón."
      >
        <template #body>
          <div class="space-y-3">
            <UFormField
              label="Tipo de coincidencia"
              name="match_type"
            >
              <USelect
                v-model="createRuleMatchType"
                :items="[
                  { label: 'Contiene', value: 'contains' },
                  { label: 'Empieza con', value: 'starts_with' },
                  { label: 'Exacto', value: 'exact' },
                ]"
                color="neutral"
                variant="outline"
                size="md"
                class="w-full"
              />
            </UFormField>
            <UFormField
              label="Patrón"
              name="pattern"
            >
              <UInput
                v-model="createRulePattern"
                color="neutral"
                variant="outline"
                size="md"
                class="w-full"
              />
            </UFormField>
            <UFormField
              label="Categoría"
              name="category"
            >
              <USelect
                v-model="createRuleCategory"
                :items="categoryOptions"
                color="neutral"
                variant="outline"
                size="md"
                class="w-full"
              />
            </UFormField>
          </div>
        </template>
        <template #footer>
          <div class="flex justify-end gap-2">
            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              label="Cancelar"
              @click="createRuleOpen = false"
            />
            <UButton
              color="primary"
              variant="solid"
              size="sm"
              label="Crear regla"
              @click="submitCreateRule"
            />
          </div>
        </template>
      </UModal>
    </AppPage>
  </AppShell>
</template>
EOF