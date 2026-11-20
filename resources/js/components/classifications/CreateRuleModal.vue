<script setup lang="ts">
import { ref, watch } from 'vue';

import { postJson } from '@/lib/api';

const props = defineProps<{
    open: boolean;
    sourceId: number | null;
    category: number;
    pattern: string;
    categories: { label: string; value: number }[];
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    'update:category': [value: number];
    'update:pattern': [value: string];
    created: [];
}>();

const openModel = ref(props.open);
const matchType = ref('contains');
const submitting = ref(false);
const error = ref<string | null>(null);

watch(() => props.open, (value) => {
    openModel.value = value;
    if (value) error.value = null;
});
watch(openModel, (value) => emit('update:open', value));

async function submit(): Promise<void> {
    if (props.sourceId === null) return;
    submitting.value = true;
    error.value = null;
    try {
        await postJson('/api/v1/classification-rules', {
            match_type: matchType.value,
            pattern: props.pattern,
            expense_category_id: props.category > 0 ? props.category : undefined,
            priority: 100,
            is_active: true,
        });
        emit('created');
        openModel.value = false;
    } catch (caught: unknown) {
        error.value = caught instanceof Error ? caught.message : 'No se pudo crear la regla.';
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
  <UModal
    v-model:open="openModel"
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
            v-model="matchType"
            :items="[{ label: 'Contiene', value: 'contains' }, { label: 'Empieza con', value: 'starts_with' }, { label: 'Exacto', value: 'exact' }]"
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
            :model-value="pattern"
            color="neutral"
            variant="outline"
            size="md"
            class="w-full"
            @update:model-value="(value: string) => emit('update:pattern', value)"
          />
        </UFormField>
        <UFormField
          label="Categoría"
          name="category"
        >
          <USelect
            :model-value="category"
            :items="categories"
            color="neutral"
            variant="outline"
            size="md"
            class="w-full"
            @update:model-value="(value: number) => emit('update:category', value)"
          />
        </UFormField>
        <p
          v-if="error"
          class="text-sm text-error"
        >
          {{ error }}
        </p>
      </div>
    </template>
    <template #footer>
      <div class="flex justify-end gap-2">
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          label="Cancelar"
          @click="openModel = false"
        />
        <UButton
          color="primary"
          variant="solid"
          size="sm"
          label="Crear regla"
          :loading="submitting"
          @click="submit"
        />
      </div>
    </template>
  </UModal>
</template>