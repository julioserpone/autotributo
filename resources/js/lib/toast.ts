import { readonly, ref } from 'vue';

import type { ToastMessage, ToastOptions, ToastTone } from '@/components/ui/types';

const MAX_VISIBLE = 5;
const DEFAULT_DURATION = 5_000;
const ERROR_DURATION = 8_000;

const toasts = ref<ToastMessage[]>([]);
const timers = new Map<number, ReturnType<typeof setTimeout>>();
let nextId = 1;

function dismiss(id: number): void {
    const timer = timers.get(id);

    if (timer !== undefined) {
        clearTimeout(timer);
        timers.delete(id);
    }

    toasts.value = toasts.value.filter((toast) => toast.id !== id);
}

function show(tone: ToastTone, title: string, options: ToastOptions = {}): number {
    const id = nextId++;
    const duration = options.duration ?? (tone === 'error' ? ERROR_DURATION : DEFAULT_DURATION);

    toasts.value = [
        ...toasts.value.slice(-(MAX_VISIBLE - 1)),
        {
            id,
            tone,
            title,
            description: options.description,
            duration,
        },
    ];

    if (duration > 0) {
        timers.set(
            id,
            setTimeout(() => dismiss(id), duration),
        );
    }

    return id;
}

/**
 * Notificaciones efímeras de la aplicación.
 *
 * El estado vive en el módulo para que cualquier componente pueda avisar sin
 * prop drilling; `AppToastHost` se monta una sola vez en `App.vue`. El
 * auto-cierre se desactiva con `duration: 0` y las de error duran más porque
 * suelen requerir más lectura.
 */
export function useToast() {
    return {
        toasts: readonly(toasts),
        dismiss,
        show,
        success: (title: string, options?: ToastOptions): number => show('success', title, options),
        error: (title: string, options?: ToastOptions): number => show('error', title, options),
        warning: (title: string, options?: ToastOptions): number => show('warning', title, options),
        info: (title: string, options?: ToastOptions): number => show('info', title, options),
    };
}

/** Limpia el estado entre tests; en la app no se usa. */
export function resetToasts(): void {
    for (const timer of timers.values()) {
        clearTimeout(timer);
    }

    timers.clear();
    toasts.value = [];
    nextId = 1;
}
