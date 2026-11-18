import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { getJson } = vi.hoisted(() => ({ getJson: vi.fn() }));

vi.mock('@/lib/api', () => ({ getJson, postJson: vi.fn() }));

import { useSyncStore } from '@/stores/sync';

const runPayload = {
    id: '01M7QJ2WTVR78F2H5D6SBJAF8N',
    company_id: null,
    status: 'downloading',
    is_active: true,
    poll_after_ms: 5000,
    date_from: '2026-08-01',
    date_to: '2026-08-31',
    periods: ['2026-08'],
    document_types: ['01'],
    totals: { found: 24, imported: 20, skipped: 0, failed: 0 },
    progress: 83.3,
    error_code: null,
    error_message: null,
    started_at: null,
    finished_at: null,
    created_at: null,
};

describe('useSyncStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
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

    it('marca loading solo en la primera carga del historial', async () => {
        let resolveFirst: (value: unknown) => void = () => {};
        getJson.mockImplementationOnce(() => new Promise((resolve) => {
            resolveFirst = resolve;
        }));

        const store = useSyncStore();
        const first = store.fetchRuns();

        expect(store.loading).toBe(true);

        resolveFirst({ data: [runPayload] });
        await first;

        expect(store.loading).toBe(false);
        expect(store.runs).toHaveLength(1);
    });

    it('no vuelve a loading cuando refresca un historial ya cargado', async () => {
        getJson.mockResolvedValue({ data: [runPayload] });

        const store = useSyncStore();
        await store.fetchRuns();

        const refresh = store.fetchRuns();

        // La lista sigue en pantalla: el refresco es silencioso.
        expect(store.loading).toBe(false);

        await refresh;
    });
});
