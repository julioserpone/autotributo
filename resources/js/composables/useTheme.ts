import { computed, readonly, ref } from 'vue';

export type Theme = 'light' | 'dark';

const STORAGE_KEY = 'autotributo.theme';

const theme = ref<Theme>('dark');

let initialized = false;

/**
 * Aplica el tema al `<html>`: alterna las clases `.dark`/`.light` y el
 * `color-scheme`, para que los componentes nativos y los tokens CSS reaccionen.
 */
export function applyTheme(value: Theme): void {
    if (typeof document === 'undefined') {
        return;
    }

    const root = document.documentElement;
    root.classList.toggle('dark', value === 'dark');
    root.classList.toggle('light', value === 'light');
    root.style.colorScheme = value;
}

function storedTheme(): Theme | null {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        const value = window.localStorage.getItem(STORAGE_KEY);
        return value === 'light' || value === 'dark' ? value : null;
    } catch {
        return null;
    }
}

export function resetTheme(): void {
    initialized = false;
}

export function initTheme(): Theme {
    if (!initialized) {
        theme.value = storedTheme() ?? 'dark';
        initialized = true;
    }

    applyTheme(theme.value);

    return theme.value;
}

export function useTheme() {
    initTheme();

    const isDark = computed(() => theme.value === 'dark');

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
        setTheme(isDark.value ? 'light' : 'dark');
    }

    return {
        theme: readonly(theme),
        isDark,
        setTheme,
        toggleTheme,
    };
}
