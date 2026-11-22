import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import SyncMonitorPage from '@/pages/SyncMonitorPage.vue';

vi.mock('@/stores/company', () => ({
    useCompanyStore: () => ({
        loaded: true,
        loading: false,
        companies: [],
        activeId: null,
        activeCompany: null,
        hasCompany: false,
        requiresTwoFactorSetup: false,
        error: null,
        fetchCompanies: vi.fn(),
    }),
}));

vi.mock('@/stores/sync', () => ({
    useSyncStore: () => ({
        runs: [],
        loading: false,
        error: null,
        activeRuns: [],
        selectedRunId: null,
        fetchRuns: vi.fn(),
        stopPolling: vi.fn(),
        clearSelection: vi.fn(),
        monthsBetween: () => [],
        startSync: vi.fn(),
        loadRun: vi.fn(),
    }),
}));

describe('SyncMonitorPage', () => {
    let pinia: ReturnType<typeof createPinia>;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
    });

    it('muestra el estado vacío cuando no hay contribuyentes', async () => {
        const router = createRouter({
            history: createMemoryHistory(),
            routes: [{ path: '/sync', name: 'sync', component: SyncMonitorPage }],
        });
        await router.push('/sync');

        const wrapper = mount(SyncMonitorPage, {
            global: { plugins: [pinia, router], stubs: { AppSidebar: true, UDashboardSearch: true } },
        });

        expect(wrapper.text()).toContain('Registra tu primer contribuyente');
        expect(wrapper.text()).toContain('Registrar contribuyente');
    });
});