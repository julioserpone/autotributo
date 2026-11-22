<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

import { getJson } from '@/lib/api';

interface ActivityCode {
    code: string;
    description: string;
}

const props = defineProps<{
    modelValue: string | null;
    error?: string | boolean;
    open?: boolean;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string | null];
}>();

const activityCodes = ref<ActivityCode[]>([]);
const loadingActivityCodes = ref(false);
const activitySearch = ref('');
const activityOffset = ref(0);
const activityHasMore = ref(false);
const selectedDescription = ref('');
const selectedItem = ref<ActivityCode | null>(null);
const loadingDescription = ref(false);
const atListEnd = ref(false);

/** Descripciones ya resueltas: evita el parpadeo en blanco al reabrir el formulario. */
const descriptionCache = new Map<string, string>();

let searchTimer: ReturnType<typeof setTimeout> | null = null;
let requestId = 0;
let descriptionRequestId = 0;

const items = computed<ActivityCode[]>(() => {
    const selected = selectedItem.value;

    if (selected !== null && !activityCodes.value.some((item) => item.code === selected.code)) {
        return [selected, ...activityCodes.value];
    }

    return activityCodes.value;
});

async function fetchCodes(query: string, offset = 0, append = false): Promise<void> {
    const currentRequest = ++requestId;
    loadingActivityCodes.value = true;

    try {
        const params = new URLSearchParams();
        const trimmed = query.trim();

        if (trimmed !== '') {
            params.set('q', trimmed);
        }

        params.set('limit', trimmed !== '' ? '50' : '10');
        params.set('offset', String(offset));

        const response = await getJson<{ data: ActivityCode[]; meta?: { total: number; limit: number; offset: number } }>(
            `/api/v1/economic-activity-codes?${params.toString()}`,
        );

        // Descarta respuestas viejas (búsquedas que llegan fuera de orden).
        if (currentRequest !== requestId) {
            return;
        }

        const incoming = response.data ?? [];

        if (append) {
            activityCodes.value = [...activityCodes.value, ...incoming];
        } else {
            activityCodes.value = incoming;
        }

        const limit = response.meta?.limit ?? (trimmed !== '' ? 50 : 10);
        activityOffset.value = (append ? offset : 0) + incoming.length;
        activityHasMore.value = incoming.length >= limit;
    } catch {
        if (currentRequest !== requestId) {
            return;
        }

        if (!append) {
            activityCodes.value = [];
        }

        activityHasMore.value = false;
    } finally {
        if (currentRequest === requestId) {
            loadingActivityCodes.value = false;
        }
    }
}

function onSearch(term: string): void {
    if (searchTimer !== null) {
        clearTimeout(searchTimer);
    }

    searchTimer = setTimeout(() => {
        searchTimer = null;
        activityOffset.value = 0;
        activityHasMore.value = false;
        atListEnd.value = false;
        void fetchCodes(term, 0, false);
    }, 300);
}

async function loadMore(): Promise<void> {
    await fetchCodes(activitySearch.value, activityOffset.value, true);
    await nextTick();
    // El alto del listbox crece al añadir ítems; recalcula si seguimos al final.
    window.requestAnimationFrame(() => measureListEnd());
}

let viewportEl: HTMLElement | null = null;
let detachViewport: (() => void) | null = null;

function measureListEnd(): void {
    if (viewportEl === null) {
        atListEnd.value = false;
        return;
    }

    const scrollable = viewportEl.scrollHeight - viewportEl.clientHeight;
    const remaining = scrollable - viewportEl.scrollTop;

    // Solo hay "final de lista" si el contenido realmente se puede desplazar.
    atListEnd.value = scrollable > 8 && remaining < 64;
}

function onViewportScroll(): void {
    measureListEnd();

    if (atListEnd.value && activityHasMore.value && !loadingActivityCodes.value) {
        void loadMore();
    }
}

function detachScrollLoader(): void {
    detachViewport?.();
    detachViewport = null;
    viewportEl = null;
    atListEnd.value = false;
}

async function attachScrollLoader(): Promise<void> {
    detachScrollLoader();
    await nextTick();

    const viewport = document.querySelector<HTMLElement>(
        '[data-reka-popper-content-wrapper] [data-slot="viewport"]',
    );

    if (viewport === null) {
        return;
    }

    viewportEl = viewport;
    viewport.addEventListener('scroll', onViewportScroll, { passive: true });
    detachViewport = () => viewport.removeEventListener('scroll', onViewportScroll);
    atListEnd.value = false;
}

function onOpenChange(open: boolean): void {
    if (open) {
        void attachScrollLoader();
    } else {
        detachScrollLoader();
    }
}

async function ensureDescription(code: string | null): Promise<void> {
    const currentRequest = ++descriptionRequestId;

    if (!code) {
        loadingDescription.value = false;
        selectedDescription.value = '';
        selectedItem.value = null;
        return;
    }

    const cached = descriptionCache.get(code);

    if (cached !== undefined) {
        loadingDescription.value = false;
        selectedItem.value = { code, description: cached };
        selectedDescription.value = cached;
        return;
    }

    const found = activityCodes.value.find((item) => item.code === code);

    if (found) {
        loadingDescription.value = false;
        descriptionCache.set(code, found.description);
        selectedItem.value = found;
        selectedDescription.value = found.description;
        return;
    }

    loadingDescription.value = true;

    try {
        const response = await getJson<{ data: ActivityCode[] }>(
            `/api/v1/economic-activity-codes?q=${encodeURIComponent(code)}&limit=20`,
        );

        if (currentRequest !== descriptionRequestId) {
            return;
        }

        const match = response.data?.find((item) => item.code === code) ?? null;

        if (match !== null) {
            descriptionCache.set(code, match.description);
        }

        selectedItem.value = match;
        selectedDescription.value = match?.description ?? '';
    } catch {
        if (currentRequest !== descriptionRequestId) {
            return;
        }

        selectedDescription.value = '';
    } finally {
        if (currentRequest === descriptionRequestId) {
            loadingDescription.value = false;
        }
    }
}

function onSelect(value: unknown): void {
    if (searchTimer !== null) {
        clearTimeout(searchTimer);
        searchTimer = null;
    }

    const code = typeof value === 'string' && value !== '' ? value : null;

    if (code === null) {
        selectedItem.value = null;
        selectedDescription.value = '';
        emit('update:modelValue', null);
        return;
    }

    const found = activityCodes.value.find((item) => item.code === code);

    if (found) {
        descriptionCache.set(code, found.description);
        selectedItem.value = found;
        selectedDescription.value = found.description;
    } else {
        void ensureDescription(code);
    }

    emit('update:modelValue', code);
}

watch(() => props.modelValue, (value) => {
    void ensureDescription(value);
}, { immediate: true });

watch(() => props.open, (value) => {
    if (value) {
        activityCodes.value = [];
        activitySearch.value = '';
        activityOffset.value = 0;
        activityHasMore.value = false;
        atListEnd.value = false;
        void fetchCodes('');
        void ensureDescription(props.modelValue);
    }
});

onMounted(() => {
    if (props.open) {
        void fetchCodes('');
        void ensureDescription(props.modelValue);
    }
});

onBeforeUnmount(() => {
    detachScrollLoader();

    if (searchTimer !== null) {
        clearTimeout(searchTimer);
    }
});
</script>

<template>
  <div class="contents">
    <UFormField
      name="economic_activity_code"
      :error="error ?? false"
    >
      <template #label>
        <span class="flex items-center gap-1">
          Actividad económica
          <UTooltip text="Código CIIU del SRI: clasifica la actividad principal del contribuyente.">
            <UButton
              type="button"
              color="neutral"
              variant="ghost"
              size="xs"
              icon="i-lucide-help-circle"
              square
              aria-label="Qué significa la actividad económica"
              @click.prevent.stop
            />
          </UTooltip>
        </span>
      </template>
      <USelectMenu
        v-model:search-term="activitySearch"
        :model-value="modelValue ?? ''"
        :items="items"
        value-key="code"
        label-key="code"
        description-key="description"
        :ignore-filter="true"
        :virtualize="true"
        :loading="loadingActivityCodes"
        searchable
        placeholder="Escribe el código o la descripción…"
        color="neutral"
        variant="soft"
        size="md"
        class="w-full"
        :content="{ align: 'start', side: 'bottom', sideOffset: 4 }"
        :ui="{ content: 'w-[32rem] max-w-[90vw]', itemLabel: 'text-[11px] font-medium', itemDescription: 'text-[10px] text-dimmed' }"
        @update:model-value="onSelect"
        @update:open="onOpenChange"
        @update:search-term="onSearch"
      >
        <template #empty>
          <div class="px-3 py-2 text-xs text-muted">
            {{ loadingActivityCodes ? 'Cargando…' : 'Sin resultados. Escribe para buscar.' }}
          </div>
        </template>
        <template #content-bottom>
          <div
            v-if="activityHasMore && atListEnd"
            class="flex justify-center border-t border-default p-2"
          >
            <UButton
              color="neutral"
              variant="ghost"
              size="xs"
              :loading="loadingActivityCodes"
              label="Cargar más"
              @click="loadMore"
            />
          </div>
        </template>
      </USelectMenu>
    </UFormField>

    <UFormField
      label="Descripción de la actividad"
      name="economic_activity_description"
      class="sm:col-span-2"
    >
      <UInput
        :model-value="selectedDescription"
        :placeholder="loadingDescription ? 'Cargando descripción…' : ''"
        color="neutral"
        variant="soft"
        size="md"
        readonly
        class="w-full"
      />
    </UFormField>
  </div>
</template>
