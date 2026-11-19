import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import AppShell from '@/layouts/AppShell.vue';

vi.mock('@/stores/health', () => ({
    useHealthStore: () => ({ status: 'ok', check: vi.fn() }),
}));

vi.mock('@/stores/company', () => ({
    useCompanyStore: () => ({
        loaded: true,
        activeCompany: null,
        fetchCompanies: vi.fn(),
    }),
}));

describe('AppShell', () => {
    let pinia: ReturnType<typeof createPinia>;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
    });

    it('renderiza el skip link y el contenido de la página', async () => {
        const router = createRouter({
            history: createMemoryHistory(),
            routes: [{ path: '/', name: 'dashboard', component: { template: '<div />' } }],
        });
        await router.push('/');

        const wrapper = mount(AppShell, {
            slots: { default: '<p>Contenido</p>' },
            global: {
                plugins: [pinia, router],
                stubs: { AppSidebar: true, UDashboardSearch: true },
            },
        });

        expect(wrapper.get('a[href="#contenido"]').text()).toContain('Saltar al contenido');
        expect(wrapper.text()).toContain('Contenido');
    });
});
