<script setup lang="ts">
import { cva } from 'class-variance-authority';
import { computed } from 'vue';

import { cn } from '@/lib/cn';

import type { Tone } from './types';

const props = withDefaults(
    defineProps<{
        tone?: Tone;
        size?: 'sm' | 'md';
        dot?: boolean;
    }>(),
    {
        tone: 'neutral',
        size: 'sm',
        dot: false,
    },
);

const badge = cva('inline-flex items-center gap-2 rounded-full border font-medium', {
    variants: {
        tone: {
            neutral: 'border-line-strong bg-raised text-muted',
            accent: 'border-accent/40 bg-accent-soft text-accent-ink',
            success: 'border-success/40 bg-success-soft text-success-ink',
            warning: 'border-warning/40 bg-warning-soft text-warning-ink',
            danger: 'border-danger/40 bg-danger-soft text-danger-ink',
        },
        size: {
            sm: 'px-2.5 py-0.5 text-xs',
            md: 'px-3 py-1 text-sm',
        },
    },
    defaultVariants: {
        tone: 'neutral',
        size: 'sm',
    },
});

const classes = computed(() => cn(badge({ tone: props.tone, size: props.size })));
</script>

<template>
  <span :class="classes">
    <span
      v-if="dot"
      class="size-1.5 rounded-full bg-current"
      aria-hidden="true"
    />
    <slot />
  </span>
</template>
