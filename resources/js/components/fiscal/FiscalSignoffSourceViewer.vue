<script setup lang="ts">
import { computed, ref, watch } from 'vue';

import type {
    FiscalSignoffSourceFile,
    FiscalSignoffSourceFileContent,
    FiscalSignoffStage,
} from '@/stores/fiscalSignoffs';
import { useFiscalSignoffStore } from '@/stores/fiscalSignoffs';

const props = defineProps<{
    open: boolean;
    stage: FiscalSignoffStage | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const store = useFiscalSignoffStore();

const files = ref<FiscalSignoffSourceFile[]>([]);
const selectedId = ref<string | null>(null);
const content = ref<FiscalSignoffSourceFileContent | null>(null);
const loadingList = ref(false);
const loadingContent = ref(false);
const error = ref<string | null>(null);

const openModel = computed({
    get: () => props.open,
    set: (value: boolean) => emit('update:open', value),
});

const lines = computed(() => {
    if (content.value === null) {
        return [];
    }

    return content.value.content.split(/\r?\n/);
});

const selectedFile = computed(() => files.value.find((file) => file.id === selectedId.value) ?? null);

function reset(): void {
    files.value = [];
    selectedId.value = null;
    content.value = null;
    error.value = null;
    loadingList.value = false;
    loadingContent.value = false;
}

async function loadFiles(): Promise<void> {
    if (props.stage === null) {
        return;
    }

    loadingList.value = true;
    error.value = null;
    files.value = [];
    selectedId.value = null;
    content.value = null;

    try {
        files.value = await store.fetchSourceFiles(props.stage.key);

        if (files.value.length > 0) {
            selectedId.value = files.value[0]?.id ?? null;
        }
    } catch (caught: unknown) {
        error.value = caught instanceof Error ? caught.message : 'No se pudieron cargar los archivos fuente.';
    } finally {
        loadingList.value = false;
    }
}

async function loadContent(): Promise<void> {
    if (props.stage === null || selectedId.value === null) {
        return;
    }

    loadingContent.value = true;
    error.value = null;
    content.value = null;

    try {
        content.value = await store.fetchSourceFile(props.stage.key, selectedId.value);
    } catch (caught: unknown) {
        error.value = caught instanceof Error ? caught.message : 'No se pudo leer el archivo fuente.';
    } finally {
        loadingContent.value = false;
    }
}

watch(
    () => [props.open, props.stage?.key] as const,
    async ([isOpen]) => {
        if (isOpen) {
            await loadFiles();
        } else {
            reset();
        }
    },
    { immediate: true },
);

watch(selectedId, (value, previous) => {
    if (props.open && value !== null && value !== previous) {
        void loadContent();
    }
});
</script>

<template>
  <UModal
    v-model:open="openModel"
    :title="stage ? `Código fuente: ${stage.title}` : 'Código fuente'"
    :description="stage ? 'Archivos permitidos para esta etapa de validación.' : ''"
    :ui="{ content: 'sm:max-w-5xl' }"
  >
    <template #body>
      <div
        v-if="loadingList"
        class="py-8 text-center text-sm text-muted"
        role="status"
      >
        Cargando archivos fuente…
      </div>

      <div
        v-else-if="error"
        class="rounded-lg border border-error/40 bg-error/5 p-4 text-sm text-error"
        role="alert"
      >
        {{ error }}
      </div>

      <div
        v-else-if="files.length === 0"
        class="rounded-lg border border-default bg-elevated/50 p-4 text-sm text-muted"
      >
        No hay código fuente disponible para esta etapa.
      </div>

      <div
        v-else
        class="space-y-4"
      >
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
          <label
            class="text-sm font-medium text-highlighted"
            for="fiscal-signoff-source-file"
          >
            Archivo
          </label>
          <select
            id="fiscal-signoff-source-file"
            v-model="selectedId"
            class="w-full rounded-md border border-default bg-default px-3 py-2 text-sm sm:max-w-md"
          >
            <option
              v-for="file in files"
              :key="file.id"
              :value="file.id"
            >
              {{ file.label }}
            </option>
          </select>
        </div>

        <div
          v-if="selectedFile"
          class="text-sm text-muted"
        >
          {{ selectedFile.description }}
        </div>

        <div
          v-if="content"
          class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-dimmed"
        >
          <span>{{ content.line_count }} líneas</span>
          <span>{{ content.size_bytes }} bytes</span>
          <span>SHA-256: {{ content.sha256.slice(0, 16) }}…</span>
        </div>

        <div
          v-if="loadingContent"
          class="py-8 text-center text-sm text-muted"
          role="status"
        >
          Cargando contenido…
        </div>

        <pre
          v-else-if="content"
          class="max-h-[65vh] overflow-auto rounded-lg border border-default bg-elevated p-4 text-xs leading-5"
        ><code><span
          v-for="(line, index) in lines"
          :key="index"
          class="block"
        ><span class="mr-4 inline-block w-10 select-none text-right text-dimmed">{{ index + 1 }}</span>{{ line }}</span></code></pre>
      </div>
    </template>

    <template #footer>
      <UButton
        color="neutral"
        variant="solid"
        size="sm"
        label="Cerrar"
        @click="openModel = false"
      />
    </template>
  </UModal>
</template>
