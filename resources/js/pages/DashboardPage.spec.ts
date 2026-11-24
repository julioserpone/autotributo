import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import DashboardPage from '@/pages/DashboardPage.vue';
import { useDashboardStore } from '@/stores/dashboard';

const flags = {
    loaded: true,
    loading: false,
    hasCompany: false,
    requiresTwoFactorSetup: false,
    activeCompany: null as { id: string; legal_name: string } | null,
};

/** Doble plano del store: la plantilla lee banderas sin reactividad. */
const companyStoreMock = {
    get loaded() {
        return flags.loaded;
    },
    get loading() {
        return flags.loading;
    },
    get hasCompany() {
        return flags.hasCompany;
    },
    get requiresTwoFactorSetup() {
        return flags.requiresTwoFactorSetup;
    },
    get activeCompany() {
        return flags.activeCompany;
    },
    fetchCompanies: vi.fn(async () => undefined),
};

vi.mock('@/stores/company', () => ({
    useCompanyStore: () => companyStoreMock,
}));

vi.mock('@/stores/health', () => ({
    useHealthStore: () => ({ status: 'ok', check: vi.fn() }),
}));

function buildRouter() {
    return createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'dashboard', component: DashboardPage },
            { path: '/fiscal-documents', name: 'fiscal_documents', component: { template: '<div />' } },
            { path: '/fiscal-findings', name: 'fiscal-findings', component: { template: '<div />' } },
            { path: '/sync', name: 'sync', component: { template: '<div />' } },
            { path: '/two-factor-setup', name: 'two-factor-setup', component: { template: '<div />' } },
            { path: '/companies', name: 'companies', component: { template: '<div />' } },
        ],
    });
}

function mountPage(router: ReturnType<typeof buildRouter>, pinia: ReturnType<typeof createPinia>) {
    return mount(DashboardPage, {
        global: { plugins: [pinia, router], stubs: { AppSidebar: true, UDashboardSearch: true } },
    });
}

describe('DashboardPage', () => {
    let pinia: ReturnType<typeof createPinia>;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        flags.loaded = true;
        flags.loading = false;
        flags.hasCompany = false;
        flags.activeCompany = null;
    });

    it('muestra el estado vacío fuera de la vista previa', async () => {
        const router = buildRouter();
        await router.push('/');
        await router.isReady();

        const wrapper = mountPage(router, pinia);

        expect(wrapper.text()).toContain('Registra tu primer contribuyente');
        expect(wrapper.text()).not.toContain('Vista previa de interfaz');
    });

    it('no enseña el estado vacío mientras las empresas siguen cargando', async () => {
        flags.loaded = false;
        const router = buildRouter();
        await router.push('/');
        await router.isReady();

        const wrapper = mountPage(router, pinia);

        expect(wrapper.text()).not.toContain('Registra tu primer contribuyente');
    });

    it('marca la vista previa como interfaz sin datos de ejemplo', async () => {
        flags.hasCompany = true;
        flags.activeCompany = { id: 'company-1', legal_name: 'ACME C.A.' };
        const router = buildRouter();
        await router.push('/?preview=1');
        await router.isReady();

        const wrapper = mountPage(router, pinia);

        expect(wrapper.text()).toContain('Vista previa de interfaz');
        expect(wrapper.text()).not.toContain('CORPORACIÓN FAVORITA C.A.');
    });

    it('pide el panel de la empresa activa y pinta las cifras del período', async () => {
        flags.hasCompany = true;
        flags.activeCompany = { id: 'company-1', legal_name: 'ACME C.A.' };

        const store = useDashboardStore();
        store.setPeriod(2026, 9);
        store.dashboard = {
            company: { public_id: 'company-1', legal_name: 'ACME C.A.', ruc: '1790012345001' },
            period: { year: 2026, month: 9, status: 'closed', computed_at: null },
            iva: {
                sales: { base_cents: 100_000, tax_cents: 15_000, documents_count: 3 },
                purchases: { base_cents: 50_000, tax_cents: 7_500, documents_count: 2 },
                net_cents: 7_500,
            },
            withholdings: {
                received_cents: 3_000,
                received_documents: 1,
                practiced_cents: 2_000,
                practiced_documents: 1,
            },
            top_providers: [],
            findings: { open_total: 0, by_severity: {} },
            personal_expense_caps: [],
        };

        const fetchSpy = vi.spyOn(store, 'fetchDashboard').mockResolvedValue(undefined);

        const router = buildRouter();
        await router.push('/');
        await router.isReady();

        const wrapper = mountPage(router, pinia);
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(fetchSpy).toHaveBeenCalledWith('company-1');
        expect(wrapper.text()).toContain('ACME C.A.');
        expect(wrapper.text()).toContain('$150.00');
        expect(wrapper.text()).toContain('$75.00');
        expect(wrapper.text()).toContain('Cerrado');
        expect(wrapper.text()).not.toContain('Sin comprobantes en el período');
    });

    it('avisa cuando el período no tiene comprobantes', async () => {
        flags.hasCompany = true;
        flags.activeCompany = { id: 'company-1', legal_name: 'ACME C.A.' };

        const store = useDashboardStore();
        store.dashboard = {
            company: { public_id: 'company-1', legal_name: 'ACME C.A.', ruc: '1790012345001' },
            period: { year: 2026, month: 9, status: null, computed_at: null },
            iva: {
                sales: { base_cents: 0, tax_cents: 0, documents_count: 0 },
                purchases: { base_cents: 0, tax_cents: 0, documents_count: 0 },
                net_cents: 0,
            },
            withholdings: {
                received_cents: 0,
                received_documents: 0,
                practiced_cents: 0,
                practiced_documents: 0,
            },
            top_providers: [],
            findings: { open_total: 0, by_severity: {} },
            personal_expense_caps: [],
        };

        vi.spyOn(store, 'fetchDashboard').mockResolvedValue(undefined);

        const router = buildRouter();
        await router.push('/');
        await router.isReady();

        const wrapper = mountPage(router, pinia);
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(wrapper.text()).toContain('Sin comprobantes en el período');
        expect(wrapper.text()).toContain('Aún no hay compras registradas en este período');
    });
});
