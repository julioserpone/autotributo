import { beforeEach, describe, expect, it } from 'vitest';

import { applyTheme, initTheme, resetTheme, useTheme } from '@/composables/useTheme';

describe('useTheme', () => {
    beforeEach(() => {
        resetTheme();
        window.localStorage.clear();
        document.documentElement.classList.remove('dark', 'light');
        document.documentElement.style.colorScheme = '';
    });

    it('aplica el tema oscuro por defecto y lo persiste', () => {
        const { theme, isDark, setTheme } = useTheme();

        // Default is dark on first use.
        expect(isDark.value).toBe(true);
        expect(document.documentElement.classList.contains('dark')).toBe(true);

        setTheme('light');

        expect(theme.value).toBe('light');
        expect(isDark.value).toBe(false);
        expect(document.documentElement.classList.contains('light')).toBe(true);
        expect(window.localStorage.getItem('autotributo.theme')).toBe('light');
    });

    it('alterna el tema', () => {
        const { toggleTheme, isDark } = useTheme();
        const before = isDark.value;
        toggleTheme();
        expect(isDark.value).toBe(!before);
    });

    it('applyTheme actualiza color-scheme', () => {
        applyTheme('dark');
        expect(document.documentElement.style.colorScheme).toBe('dark');
        initTheme();
    });
});
