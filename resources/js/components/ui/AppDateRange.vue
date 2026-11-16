<script setup lang="ts">
import { computed, useId } from 'vue';

import { cn } from '@/lib/cn';
import { controlClass, invalidControlClass } from '@/lib/ui';

import AppField from './AppField.vue';
import type { DateRangeValue } from './types';

const props = withDefaults(
    defineProps<{
        modelValue: DateRangeValue;
        label?: string | undefined;
        hint?: string | undefined;
        error?: string | null | undefined;
        id?: string | undefined;
        min?: string | undefined;
        max?: string | undefined;
        required?: boolean;
        disabled?: boolean;
        clearable?: boolean;
    }>(),
    {
        label: undefined,
        hint: undefined,
        id: undefined,
        min: undefined,
        max: undefined,
        error: null,
        required: false,
        disabled: false,
        clearable: true,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: DateRangeValue];
}>();

const generated = useId();
const groupId = computed(() => props.id ?? `date-range-${generated}`);

const rangeError = computed(() => {
    const { from, to } = props.modelValue;

    if (from !== null && to !== null && from !== '' && to !== '' && from > to) {
        return 'La fecha inicial no puede ser posterior a la final.';
    }

    return null;
});

const message = computed(() => props.error ?? rangeError.value);
const hasValue = computed(() => Boolean(props.modelValue.from || props.modelValue.to));

function update(part: 'from' | 'to', value: string): void {
    emit('update:modelValue', {
        ...props.modelValue,
        [part]: value === '' ? null : value,
    });
}

function clear(): void {
    emit('update:modelValue', { from: null, to: null });
}
</script>

<template>
  <AppField
    :id="groupId"
    :label="label"
    :label-for="`${groupId}-from`"
    :hint="hint"
    :error="message"
    :required="required"
  >
    <template #default="{ describedBy, invalid }">
      <div
        class="flex items-end gap-2"
        role="group"
        :aria-describedby="describedBy"
      >
        <div class="min-w-0 flex-1">
          <label
            :for="`${groupId}-from`"
            class="mb-1 block text-2xs font-medium uppercase tracking-wide text-faint"
          >
            Desde
          </label>
          <input
            :id="`${groupId}-from`"
            :value="modelValue.from ?? ''"
            type="date"
            :min="min"
            :max="max ?? modelValue.to ?? undefined"
            :required="required"
            :disabled="disabled"
            :aria-invalid="invalid || undefined"
            :class="cn(controlClass, invalid && invalidControlClass)"
            @input="update('from', ($event.target as HTMLInputElement).value)"
          >
        </div>

        <div class="min-w-0 flex-1">
          <label
            :for="`${groupId}-to`"
            class="mb-1 block text-2xs font-medium uppercase tracking-wide text-faint"
          >
            Hasta
          </label>
          <input
            :id="`${groupId}-to`"
            :value="modelValue.to ?? ''"
            type="date"
            :min="min ?? modelValue.from ?? undefined"
            :max="max"
            :required="required"
            :disabled="disabled"
            :aria-invalid="invalid || undefined"
            :class="cn(controlClass, invalid && invalidControlClass)"
            @input="update('to', ($event.target as HTMLInputElement).value)"
          >
        </div>

        <button
          v-if="clearable && hasValue && !disabled"
          type="button"
          class="mb-0.5 rounded-control border border-line px-2.5 py-2 text-xs font-medium text-muted transition hover:bg-raised hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60"
          @click="clear"
        >
          Limpiar
        </button>
      </div>
    </template>
  </AppField>
</template>
