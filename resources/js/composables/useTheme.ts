import { computed, readonly, ref } from 'vue';

export type Theme = 'light' | 'dark' | 'system';

const STORAGE_KEY = 'autotributo.theme';

const theme = ref<Theme>('dark');

let initialized = false;

function resolveTheme(value: Theme): 'light' | 'dark' {
    if (value !== 'system') {
        return value;
    }

    if (typeof window === 'undefined') {
        return 'dark';
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

/**
 * Aplica el tema al `<html>`: alterna las clases `.dark`/`.light` y el
 * `color-scheme`, para que los componentes nativos y los tokens CSS reaccionen.
 */
export function applyTheme(value: Theme): void {
    if (typeof document === 'undefined') {
        return;
    }

    const resolved = resolveTheme(value);
    const root = document.documentElement;
    root.classList.toggle('dark', resolved === 'dark');
    root.classList.toggle('light', resolved === 'light');
    root.style.colorScheme = resolved;
}

function storedTheme(): Theme | null {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        const value = window.localStorage.getItem(STORAGE_KEY);
        return value === 'light' || value === 'dark' || value === 'system' ? value : null;
    } catch {
        return null;
    }
}

export function initTheme(): Theme {
    if (!initialized) {
        theme.value = storedTheme() ?? 'dark';
        initialized = true;
    }

    applyTheme(theme.value);

    return theme.value;
}

export function resetTheme(): void {
    initialized = false;
}

export function useTheme() {
    initTheme();

    const isDark = computed(() => resolveTheme(theme.value) === 'dark');

    function setTheme(next: Theme): void {
        theme.value = next;
        applyTheme(next);

        try {
            window.localStorage.setItem(STORAGE_KEY, next);
        } catch {
            // Modo privado o storage no disponible: no bloquea el cambio.
        }
    }

    function toggleTheme(): void {
        const order: Theme[] = ['light', 'dark', 'system'];
        const index = order.indexOf(theme.value as Theme);
        setTheme((order[(index + 1) % order.length] ?? 'dark') as Theme);
    }

    return {
        theme: readonly(theme),
        isDark,
        setTheme,
        toggleTheme,
    };
}
