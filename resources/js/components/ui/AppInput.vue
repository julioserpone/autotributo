<script setup lang="ts">
import { computed, ref } from 'vue';

import { cn } from '@/lib/cn';
import { controlClass, invalidControlClass } from '@/lib/ui';

import AppField from './AppField.vue';

defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        modelValue?: string | number | null;
        label?: string | undefined;
        hint?: string | undefined;
        error?: string | null | undefined;
        id?: string | undefined;
        type?: string;
        placeholder?: string | undefined;
        autocomplete?: string | undefined;
        inputmode?: 'none' | 'text' | 'decimal' | 'numeric' | 'tel' | 'search' | 'email' | 'url' | undefined;
        required?: boolean;
        disabled?: boolean;
        maxlength?: number | undefined;
    }>(),
    {
        modelValue: '',
        label: undefined,
        hint: undefined,
        id: undefined,
        placeholder: undefined,
        autocomplete: undefined,
        inputmode: undefined,
        maxlength: undefined,
        error: null,
        type: 'text',
        required: false,
        disabled: false,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const input = ref<HTMLInputElement | null>(null);

const value = computed(() => props.modelValue ?? '');

function onInput(event: Event): void {
    emit('update:modelValue', (event.target as HTMLInputElement).value);
}

defineExpose({
    focus: (): void => input.value?.focus(),
});
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
      <input
        :id="fieldId"
        ref="input"
        v-bind="$attrs"
        :value="value"
        :type="type"
        :placeholder="placeholder"
        :autocomplete="autocomplete"
        :inputmode="inputmode"
        :required="required"
        :disabled="disabled"
        :maxlength="maxlength"
        :aria-invalid="invalid || undefined"
        :aria-describedby="describedBy"
        :class="cn(controlClass, invalid && invalidControlClass)"
        @input="onInput"
      >
    </template>
  </AppField>
</template>
