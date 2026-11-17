import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it } from 'vitest';

import { useSyncStore } from '@/stores/sync';

describe('useSyncStore.monthsBetween', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
    });

    it('devuelve el mismo período cuando el rango es un solo mes', () => {
        expect(useSyncStore().monthsBetween('2026-10', '2026-10')).toEqual(['2026-10']);
    });

    it('no retrocede un mes por la zona horaria', () => {
        // `new Date('2026-10-01')` es UTC; en Ecuador caía en septiembre.
        expect(useSyncStore().monthsBetween('2026-11', '2026-11')).toEqual(['2026-11']);
    });

    it('incluye ambos extremos y cruza el cambio de año', () => {
        expect(useSyncStore().monthsBetween('2025-11', '2026-02')).toEqual([
            '2025-11',
            '2025-12',
            '2026-01',
            '2026-02',
        ]);
    });

    it('devuelve una lista vacía si el rango está invertido o es inválido', () => {
        const store = useSyncStore();

        expect(store.monthsBetween('2026-10', '2026-09')).toEqual([]);
        expect(store.monthsBetween('2026-13', '2026-14')).toEqual([]);
        expect(store.monthsBetween('', '2026-10')).toEqual([]);
        expect(store.monthsBetween('2026-10', 'octubre')).toEqual([]);
    });
});
