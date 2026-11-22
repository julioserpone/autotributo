import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import CompaniesPage from '@/pages/CompaniesPage.vue';

const { currentRole } = vi.hoisted(() => ({ currentRole: { value: 'owner' } }));

vi.mock('@/stores/company', () => ({
    useCompanyStore: () => ({
        loaded: true,
        loading: false,
        companies: [],
        activeCompany: null,
        hasCompany: false,
        requiresTwoFactorSetup: false,
        error: null,
        fetchCompanies: vi.fn(),
    }),
}));

vi.mock('@/stores/auth', () => ({
    useAuthStore: () => ({
        user: { id: 1, name: 'User', email: 'u@e.com', memberships: [{ tenant_id: 1, role: currentRole.value }] },
    }),
}));

describe('CompaniesPage', () => {
    let pinia: ReturnType<typeof createPinia>;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        currentRole.value = 'owner';
    });

    it('muestra el estado vacío con el CTA de alta', async () => {
        const router = createRouter({
            history: createMemoryHistory(),
            routes: [{ path: '/companies', name: 'companies', component: CompaniesPage }],
        });
        await router.push('/companies');

        const wrapper = mount(CompaniesPage, {
            global: { plugins: [pinia, router], stubs: { AppSidebar: true, UDashboardSearch: true } },
        });

        expect(wrapper.text()).toContain('Aún no hay contribuyentes');
        expect(wrapper.text()).toContain('Registrar contribuyente');
    });

    it('oculta el botón de alta para un viewer', async () => {
        currentRole.value = 'viewer';

        const router = createRouter({
            history: createMemoryHistory(),
            routes: [{ path: '/companies', name: 'companies', component: CompaniesPage }],
        });
        await router.push('/companies');

        const wrapper = mount(CompaniesPage, {
            global: { plugins: [pinia, router], stubs: { AppSidebar: true, UDashboardSearch: true } },
        });

        expect(wrapper.text()).not.toContain('Registrar contribuyente');
    });
});
