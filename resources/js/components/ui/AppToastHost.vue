<script setup lang="ts">
import { useToast } from '@/lib/toast';

import type { ToastTone } from './types';

const { toasts, dismiss } = useToast();

const tones: Record<ToastTone, string> = {
    info: 'border-accent/40 bg-surface text-ink',
    success: 'border-success/40 bg-surface text-ink',
    warning: 'border-warning/40 bg-surface text-ink',
    error: 'border-danger/40 bg-surface text-ink',
};

const dots: Record<ToastTone, string> = {
    info: 'bg-accent',
    success: 'bg-success',
    warning: 'bg-warning',
    error: 'bg-danger',
};

const labels: Record<ToastTone, string> = {
    info: 'Información',
    success: 'Éxito',
    warning: 'Advertencia',
    error: 'Error',
};
</script>

<template>
  <Teleport to="body">
    <div
      class="pointer-events-none fixed inset-x-0 bottom-0 z-[60] flex justify-center p-4 sm:justify-end"
      aria-label="Notificaciones"
    >
      <TransitionGroup
        tag="div"
        name="toast"
        class="flex w-full max-w-sm flex-col gap-2"
      >
        <div
          v-for="toast in toasts"
          :key="toast.id"
          :class="['pointer-events-auto flex items-start gap-3 rounded-card border p-4 shadow-panel', tones[toast.tone]]"
          :role="toast.tone === 'error' ? 'alert' : 'status'"
          :aria-live="toast.tone === 'error' ? 'assertive' : 'polite'"
        >
          <span
            :class="['mt-1.5 size-2 shrink-0 rounded-full', dots[toast.tone]]"
            aria-hidden="true"
          />

          <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-ink">
              <span class="sr-only">{{ labels[toast.tone] }}: </span>{{ toast.title }}
            </p>
            <p
              v-if="toast.description"
              class="mt-0.5 text-sm text-muted"
            >
              {{ toast.description }}
            </p>
          </div>

          <button
            type="button"
            class="rounded p-0.5 text-faint transition hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60"
            aria-label="Cerrar notificación"
            @click="dismiss(toast.id)"
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
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>

<style scoped>
.toast-enter-active,
.toast-leave-active {
    transition:
        opacity 150ms ease,
        transform 150ms ease;
}

.toast-enter-from,
.toast-leave-to {
    opacity: 0;
    transform: translateY(8px);
}
</style>
