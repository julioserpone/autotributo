import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import AppShell from '@/layouts/AppShell.vue';
import DashboardPage from '@/pages/DashboardPage.vue';

vi.mock('@/stores/health', () => ({
    useHealthStore: () => ({ status: 'ok', check: vi.fn() }),
}));

vi.mock('@/stores/company', () => ({
    useCompanyStore: () => ({
        loaded: true,
        activeCompany: { id: 'c1', trade_name: 'Empresa Demo', legal_name: 'Empresa Demo S.A.', ruc: '1790016919001' },
        fetchCompanies: vi.fn(),
    }),
}));

describe('AppShell', () => {
    let pinia: ReturnType<typeof createPinia>;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
    });

    it('renderiza el título, el skip link y el contenido', async () => {
        const router = createRouter({
            history: createMemoryHistory(),
            routes: [{ path: '/', name: 'dashboard', component: DashboardPage }],
        });
        await router.push('/');

        const wrapper = mount(AppShell, {
            props: { title: 'Panel' },
            slots: { default: '<p>Contenido</p>' },
            global: { plugins: [pinia, router], stubs: { AppSidebar: true, AppWorkspaceSwitcher: true } },
        });

        expect(wrapper.get('a[href="#contenido"]').text()).toContain('Saltar al contenido');
        expect(wrapper.get('main').attributes('id')).toBe('contenido');
        expect(wrapper.text()).toContain('Contenido');
        expect(wrapper.text()).toContain('Panel');
    });
});
