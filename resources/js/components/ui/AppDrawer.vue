<script setup lang="ts">
import {
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import { computed } from 'vue';

import { cn } from '@/lib/cn';

const props = withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        description?: string;
        side?: 'left' | 'right';
        size?: 'sm' | 'md' | 'lg';
        dismissible?: boolean;
        closeLabel?: string;
    }>(),
    {
        description: '',
        side: 'right',
        size: 'md',
        dismissible: true,
        closeLabel: 'Cerrar',
    },
);

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const widths = {
    sm: 'max-w-sm',
    md: 'max-w-md',
    lg: 'max-w-xl',
} as const;

const panelClass = computed(() =>
    cn(
        'pointer-events-auto flex h-full w-full flex-col bg-surface shadow-overlay',
        widths[props.size],
        props.side === 'right'
            ? 'border-l border-line animate-slide-in-right'
            : 'border-r border-line animate-slide-in-left',
    ),
);

function onOpenChange(value: boolean): void {
    emit('update:open', value);
}

function onEscape(event: KeyboardEvent): void {
    if (!props.dismissible) {
        event.preventDefault();
    }
}

function onOutside(event: Event): void {
    if (!props.dismissible) {
        event.preventDefault();
    }
}
</script>

<template>
  <DialogRoot
    :open="open"
    @update:open="onOpenChange"
  >
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-black/70 backdrop-blur-sm animate-fade-in" />

      <DialogContent
        class="pointer-events-none fixed inset-0 z-50 flex focus:outline-none"
        :class="side === 'right' ? 'justify-end' : 'justify-start'"
        @escape-key-down="onEscape"
        @pointer-down-outside="onOutside"
        @interact-outside="onOutside"
      >
        <div
          :class="panelClass"
          role="document"
        >
          <header class="flex items-start justify-between gap-4 border-b border-line px-6 py-5">
            <div class="min-w-0">
              <DialogTitle class="text-lg font-semibold tracking-tight text-ink">
                {{ title }}
              </DialogTitle>
              <DialogDescription
                :class="description ? 'mt-1 text-sm text-muted' : 'sr-only'"
              >
                {{ description || title }}
              </DialogDescription>
            </div>

            <DialogClose
              v-if="dismissible"
              as-child
            >
              <button
                type="button"
                class="rounded-control p-1.5 text-faint transition hover:bg-raised hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60"
                :aria-label="closeLabel"
              >
                <svg
                  class="size-4"
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
            </DialogClose>
          </header>

          <div class="flex-1 overflow-y-auto px-6 py-5">
            <slot />
          </div>

          <footer
            v-if="$slots.footer"
            class="flex flex-wrap justify-end gap-2 border-t border-line px-6 py-4"
          >
            <slot name="footer" />
          </footer>
        </div>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
