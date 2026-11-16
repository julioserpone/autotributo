<script setup lang="ts">
import {
    ComboboxAnchor,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxPortal,
    ComboboxRoot,
    ComboboxTrigger,
    ComboboxViewport,
} from 'reka-ui';

import { cn } from '@/lib/cn';
import { controlClass, invalidControlClass } from '@/lib/ui';

import AppField from './AppField.vue';
import type { ComboboxOption } from './types';

const props = withDefaults(
    defineProps<{
        modelValue?: string | number | null;
        options: ComboboxOption[];
        label?: string | undefined;
        hint?: string | undefined;
        error?: string | null | undefined;
        id?: string | undefined;
        placeholder?: string;
        emptyText?: string;
        required?: boolean;
        disabled?: boolean;
        clearable?: boolean;
    }>(),
    {
        modelValue: null,
        label: undefined,
        hint: undefined,
        id: undefined,
        error: null,
        placeholder: 'Buscar…',
        emptyText: 'Sin resultados',
        required: false,
        disabled: false,
        clearable: false,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string | number | null];
}>();

function displayValue(value: unknown): string {
    return props.options.find((option) => option.value === value)?.label ?? '';
}

function onUpdate(value: unknown): void {
    emit('update:modelValue', (value as string | number | null) ?? null);
}

function clear(): void {
    emit('update:modelValue', null);
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
      <ComboboxRoot
        :model-value="modelValue"
        :disabled="disabled"
        class="relative"
        @update:model-value="onUpdate"
      >
        <ComboboxAnchor class="relative">
          <ComboboxInput
            :id="fieldId"
            :display-value="displayValue"
            :placeholder="placeholder"
            :required="required"
            :aria-invalid="invalid || undefined"
            :aria-describedby="describedBy"
            :class="cn(controlClass, 'pr-16', invalid && invalidControlClass)"
          />

          <div class="absolute inset-y-0 right-2 flex items-center gap-1">
            <button
              v-if="clearable && modelValue !== null"
              type="button"
              class="rounded p-1 text-faint transition hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60"
              aria-label="Limpiar selección"
              @click.stop="clear"
            >
              <svg
                class="size-3.5"
                viewBox="0 0 20 20"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
                aria-hidden="true"
              >
                <path
                  d="m6 6 8 8M14 6l-8 8"
                  stroke-linecap="round"
                />
              </svg>
            </button>

            <ComboboxTrigger
              class="rounded p-1 text-faint transition hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60"
              aria-label="Mostrar opciones"
            >
              <svg
                class="size-4"
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
            </ComboboxTrigger>
          </div>
        </ComboboxAnchor>

        <ComboboxPortal>
          <ComboboxContent
            class="z-50 mt-1 w-[var(--reka-combobox-trigger-width)] overflow-hidden rounded-control border border-line bg-surface shadow-panel animate-fade-in"
          >
            <ComboboxViewport class="max-h-60 overflow-y-auto p-1">
              <ComboboxItem
                v-for="option in options"
                :key="option.value"
                :value="option.value"
                :text-value="option.label"
                :disabled="option.disabled ?? false"
                class="flex cursor-pointer flex-col gap-0.5 rounded-control px-3 py-2 text-sm text-ink outline-none data-[disabled]:cursor-not-allowed data-[disabled]:opacity-50 data-[highlighted]:bg-raised"
              >
                <span class="font-medium">{{ option.label }}</span>
                <span
                  v-if="option.description"
                  class="text-xs text-faint"
                >
                  {{ option.description }}
                </span>
              </ComboboxItem>

              <ComboboxEmpty class="px-3 py-2 text-sm text-faint">
                {{ emptyText }}
              </ComboboxEmpty>
            </ComboboxViewport>
          </ComboboxContent>
        </ComboboxPortal>
      </ComboboxRoot>
    </template>
  </AppField>
</template>
