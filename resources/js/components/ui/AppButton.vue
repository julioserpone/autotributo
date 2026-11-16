<script setup lang="ts">
import { cva } from 'class-variance-authority';
import { computed } from 'vue';

import { cn } from '@/lib/cn';

type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger';
type ButtonSize = 'sm' | 'md' | 'lg';

const props = withDefaults(
    defineProps<{
        variant?: ButtonVariant;
        size?: ButtonSize;
        type?: 'button' | 'submit' | 'reset';
        disabled?: boolean;
        loading?: boolean;
        block?: boolean;
    }>(),
    {
        variant: 'primary',
        size: 'md',
        type: 'button',
        disabled: false,
        loading: false,
        block: false,
    },
);

const emit = defineEmits<{
    click: [event: MouseEvent];
}>();

const button = cva(
    'inline-flex items-center justify-center gap-2 rounded-control font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60 focus-visible:ring-offset-2 focus-visible:ring-offset-canvas disabled:cursor-not-allowed disabled:opacity-60',
    {
        variants: {
            variant: {
                primary: 'bg-accent text-white hover:bg-accent-hover',
                secondary: 'border border-line-strong bg-raised text-ink hover:bg-line-strong',
                ghost: 'text-muted hover:bg-raised hover:text-ink',
                danger: 'bg-danger text-white hover:bg-danger-hover',
            },
            size: {
                sm: 'h-8 px-3 text-xs',
                md: 'h-10 px-4 text-sm',
                lg: 'h-11 px-5 text-sm',
            },
            block: {
                true: 'w-full',
                false: '',
            },
        },
        defaultVariants: {
            variant: 'primary',
            size: 'md',
            block: false,
        },
    },
);

const classes = computed(() =>
    cn(button({ variant: props.variant, size: props.size, block: props.block })),
);

function onClick(event: MouseEvent): void {
    if (props.disabled || props.loading) {
        event.preventDefault();

        return;
    }

    emit('click', event);
}
</script>

<template>
  <button
    :type="type"
    :disabled="disabled || loading"
    :aria-busy="loading || undefined"
    :class="classes"
    @click="onClick"
  >
    <span
      v-if="loading"
      class="size-3.5 animate-spin rounded-full border-2 border-current border-t-transparent"
      aria-hidden="true"
    />
    <slot name="icon" />
    <slot />
  </button>
</template>
