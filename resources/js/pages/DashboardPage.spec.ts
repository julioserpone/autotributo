import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import DashboardPage from '@/pages/DashboardPage.vue';

vi.mock('@/stores/company', () => ({
    useCompanyStore: () => ({
        loaded: true,
        activeCompany: null,
        fetchCompanies: vi.fn(),
        requiresTwoFactorSetup: false,
    }),
}));

vi.mock('@/stores/health', () => ({
    useHealthStore: () => ({ status: 'ok', check: vi.fn() }),
}));

describe('DashboardPage', () => {
    let pinia: ReturnType<typeof createPinia>;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
    });

    it('muestra el estado vacío fuera de la vista previa', async () => {
        const router = createRouter({
            history: createMemoryHistory(),
            routes: [{ path: '/', name: 'dashboard', component: DashboardPage }],
        });
        await router.push('/');

        const wrapper = mount(DashboardPage, { global: { plugins: [pinia, router], stubs: { AppSidebar: true, UDashboardSearch: true } } });

        expect(wrapper.text()).toContain('Sin datos todavía');
        expect(wrapper.text()).not.toContain('Vista previa con datos de ejemplo');
    });

    it('muestra la vista previa con datos de ejemplo', async () => {
        const router = createRouter({
            history: createMemoryHistory(),
            routes: [{ path: '/', name: 'dashboard', component: DashboardPage }],
        });
        await router.push('/?preview=1');

        const wrapper = mount(DashboardPage, { global: { plugins: [pinia, router], stubs: { AppSidebar: true, UDashboardSearch: true } } });

        expect(wrapper.text()).toContain('Vista previa con datos de ejemplo');
        expect(wrapper.text()).toContain('CORPORACIÓN FAVORITA C.A.');
    });
});
