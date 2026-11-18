import { mount } from '@vue/test-utils';
import { createPinia } from 'pinia';
import { describe, expect, it } from 'vitest';

import SriSyncRunHistory from '@/components/sri/SriSyncRunHistory.vue';
import type { SyncRun } from '@/stores/sync';

const run: SyncRun = {
    id: '01M7QJ2WTVR78F2H5D6SBJAF8N',
    company_id: null,
    status: 'downloading',
    is_active: true,
    poll_after_ms: 5000,
    date_from: '2026-08-01',
    date_to: '2026-08-31',
    periods: ['2026-08'],
    document_types: ['01', '04'],
    totals: { found: 24, imported: 16, updated: 4, skipped: 0, failed: 0 },
    progress: 83.3,
    error_code: null,
    error_message: null,
    started_at: null,
    finished_at: null,
    created_at: null,
};

function mountHistory(overrides: {
    runs?: SyncRun[];
    loading?: boolean;
    selectedRunId?: string | null;
} = {}) {
    return mount(SriSyncRunHistory, {
        props: {
            runs: overrides.runs ?? [run],
            loading: overrides.loading ?? false,
            selectedRunId: overrides.selectedRunId ?? null,
        },
        global: {
            plugins: [createPinia()],
        },
    });
}

describe('SriSyncRunHistory', () => {
    it('muestra el ID completo seguido del período', () => {
        const wrapper = mountHistory();

        expect(wrapper.text()).toContain('ID 01M7QJ2WTVR78F2H5D6SBJAF8N');
        expect(wrapper.text()).toContain('Periodo 2026-08');
        expect(wrapper.text()).not.toContain('01M7QJ2W…');
    });

    it('cuenta los comprobantes actualizados en el avance del item', () => {
        const wrapper = mountHistory();

        expect(wrapper.text()).toContain('20/24');
    });

    it('mantiene la lista visible mientras se refresca en segundo plano', () => {
        const wrapper = mountHistory({ loading: true });

        expect(wrapper.text()).toContain('ID 01M7QJ2WTVR78F2H5D6SBJAF8N');
        expect(wrapper.text()).not.toContain('Cargando…');
    });

    it('muestra el loader solo en la primera carga, sin runs en pantalla', () => {
        const wrapper = mountHistory({ runs: [], loading: true });

        expect(wrapper.text()).toContain('Cargando…');
    });

    it('emite el run seleccionado al hacer clic en el item', async () => {
        const wrapper = mountHistory();

        await wrapper.find('li').trigger('click');

        expect(wrapper.emitted('select')).toEqual([[run.id]]);
    });
});
