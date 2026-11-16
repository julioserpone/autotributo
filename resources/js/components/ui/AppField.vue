<script setup lang="ts">
import { computed, useId } from 'vue';

import { cn } from '@/lib/cn';

const props = withDefaults(
    defineProps<{
        label?: string | undefined;
        hint?: string | undefined;
        error?: string | null | undefined;
        required?: boolean;
        id?: string | undefined;
        labelFor?: string | undefined;
        class?: string | undefined;
    }>(),
    {
        label: undefined,
        hint: undefined,
        id: undefined,
        labelFor: undefined,
        class: undefined,
        required: false,
        error: null,
    },
);

const generated = useId();
const fieldId = computed(() => props.id ?? `field-${generated}`);
const hintId = computed(() => `${fieldId.value}-hint`);
const errorId = computed(() => `${fieldId.value}-error`);

const targetId = computed(() => props.labelFor ?? fieldId.value);

const describedBy = computed(() => {
    const ids = [props.hint ? hintId.value : null, props.error ? errorId.value : null].filter(
        (id): id is string => id !== null,
    );

    return ids.length > 0 ? ids.join(' ') : undefined;
});
</script>

<template>
  <div :class="cn('space-y-1.5', props.class)">
    <label
      v-if="label"
      :for="targetId"
      class="block text-xs font-medium uppercase tracking-wide text-muted"
    >
      {{ label }}
      <span
        v-if="required"
        class="text-danger"
        aria-hidden="true"
      >*</span>
    </label>

    <slot
      :id="fieldId"
      :described-by="describedBy"
      :invalid="Boolean(error)"
    />

    <p
      v-if="hint && !error"
      :id="hintId"
      class="text-xs text-faint"
    >
      {{ hint }}
    </p>

    <p
      v-if="error"
      :id="errorId"
      class="text-xs font-medium text-danger-ink"
      role="alert"
    >
      {{ error }}
    </p>
  </div>
</template>
