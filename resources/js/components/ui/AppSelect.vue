<script setup lang="ts">
import { computed } from 'vue';

import { cn } from '@/lib/cn';
import { controlClass, invalidControlClass } from '@/lib/ui';

import AppField from './AppField.vue';
import type { SelectOption } from './types';

const props = withDefaults(
    defineProps<{
        modelValue?: string | number | null;
        options: SelectOption[];
        label?: string | undefined;
        hint?: string | undefined;
        error?: string | null | undefined;
        id?: string | undefined;
        placeholder?: string | undefined;
        required?: boolean;
        disabled?: boolean;
    }>(),
    {
        modelValue: null,
        label: undefined,
        hint: undefined,
        id: undefined,
        placeholder: undefined,
        error: null,
        required: false,
        disabled: false,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string | number | null];
}>();

const value = computed(() => props.modelValue ?? '');

function onChange(event: Event): void {
    const raw = (event.target as HTMLSelectElement).value;

    if (raw === '') {
        emit('update:modelValue', null);

        return;
    }

    const match = props.options.find((option) => String(option.value) === raw);

    emit('update:modelValue', match ? match.value : raw);
}
</script>

<template>
  <AppField
    :id="id"
    :label="label"
    :hint="hint"
    :error="error"
    :required="required"
  >
    <template #default="{ id: fieldId, describedBy, invalid }">
      <div class="relative">
        <select
          :id="fieldId"
          :value="value"
          :required="required"
          :disabled="disabled"
          :aria-invalid="invalid || undefined"
          :aria-describedby="describedBy"
          :class="cn(controlClass, 'appearance-none pr-9', invalid && invalidControlClass)"
          @change="onChange"
        >
          <option
            v-if="placeholder"
            value=""
            :disabled="required"
          >
            {{ placeholder }}
          </option>
          <option
            v-for="option in options"
            :key="option.value"
            :value="option.value"
            :disabled="option.disabled"
          >
            {{ option.label }}
          </option>
        </select>

        <svg
          class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-faint"
          viewBox="0 0 20 20"
          fill="none"
          stroke="currentColor"
          stroke-width="1.6"
          aria-hidden="true"
        >
          <path
            d="m6 8 4 4 4-4"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
      </div>
    </template>
  </AppField>
</template>
