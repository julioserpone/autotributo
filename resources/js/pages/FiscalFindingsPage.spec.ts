import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import FiscalFindingsPage from '@/pages/FiscalFindingsPage.vue';

const finding = {
    id: 1,
    code: 'TOTALS_MISMATCH',
    severity: 'critical',
    status: 'open',
    title: 'Totales inconsistentes',
    description: 'El total no coincide con la suma de sus componentes.',
    company: { id: 'company-1', ruc: '0990000018001', legal_name: 'ESTUDIO ANDINO S.A.' },
    source_type: 'fiscal_document',
    source: {
        type: 'fiscal_document',
        id: 'doc-1',
        document_type: '01',
        number: '001-001-000000123',
        access_key: '1234567890'.repeat(5),
        issue_date: '2026-11-16',
        issuer_name: 'PROVEEDOR S.A.',
        issuer_ruc: '1758871873001',
        subtotal_cents: 10000,
        tax_cents: 1500,
        total_cents: 11500,
    },
    metadata: null,
    note: null,
    resolved_by: null,
    resolved_at: null,
    created_at: '2026-11-20T10:00:00+00:00',
    updated_at: '2026-11-20T10:00:00+00:00',
};

const companyStore = {
    loaded: true,
    loading: false,
    companies: [{ id: 'company-1', legal_name: 'ESTUDIO ANDINO S.A.', trade_name: null }],
    activeId: 'company-1',
    activeCompany: { id: 'company-1', legal_name: 'ESTUDIO ANDINO S.A.' },
    hasCompany: true,
    requiresTwoFactorSetup: false,
    fetchCompanies: vi.fn(),
};

const fiscalFindingStore = {
    items: [finding],
    loading: false,
    saving: false,
    detecting: false,
    error: null as string | null,
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    counts: { total: 1, by_status: { open: 1 }, by_severity: { critical: 1 } },
    filters: { companyId: 'all', status: 'open', severity: 'all', code: 'all', search: '' },
    openCount: 1,
    criticalCount: 1,
    warningCount: 0,
    resolvedCount: 0,
    fetchFindings: vi.fn(),
    updateFinding: vi.fn(),
    detect: vi.fn(),
    reset: vi.fn(),
};

vi.mock('@/stores/company', () => ({
    useCompanyStore: () => companyStore,
}));

vi.mock('@/stores/fiscalFindings', () => ({
    useFiscalFindingStore: () => fiscalFindingStore,
}));

vi.mock('@/stores/auth', () => ({
    useAuthStore: () => ({ user: { memberships: [{ tenant_id: 1, role: 'accountant' }] } }),
}));

vi.mock('@nuxt/ui/composables/useToast', () => ({
    useToast: () => ({ add: vi.fn() }),
}));

function mountPage() {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/fiscal-findings', name: 'fiscal-findings', component: FiscalFindingsPage },
            { path: '/fiscal_documents', name: 'fiscal_documents', component: { template: '<div />' } },
        ],
    });

    return router.push('/fiscal-findings').then(() =>
        mount(FiscalFindingsPage, {
            global: { plugins: [router], stubs: { AppSidebar: true, UDashboardSearch: true } },
        }),
    );
}

describe('FiscalFindingsPage', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        companyStore.hasCompany = true;
        fiscalFindingStore.items = [finding];
    });

    it('muestra el estado vacío cuando no hay contribuyentes', async () => {
        companyStore.hasCompany = false;

        const wrapper = await mountPage();

        expect(wrapper.text()).toContain('Registra tu primer contribuyente');
    });

    it('lista los hallazgos con contadores y acciones de revisión', async () => {
        const wrapper = await mountPage();

        expect(wrapper.text()).toContain('Hallazgos fiscales');
        expect(wrapper.text()).toContain('Totales inconsistentes');
        expect(wrapper.text()).toContain('Crítico');
        expect(wrapper.text()).toContain('Detectar ahora');
        expect(wrapper.text()).toContain('Gestionar');
    });

    it('muestra el estado vacío de la bandeja sin hallazgos', async () => {
        fiscalFindingStore.items = [];

        const wrapper = await mountPage();

        expect(wrapper.text()).toContain('No hay hallazgos abiertos para este contribuyente.');
    });

    it('muestra el estado de carga', async () => {
        fiscalFindingStore.loading = true;
        fiscalFindingStore.items = [];

        const wrapper = await mountPage();

        expect(wrapper.text()).toContain('Cargando hallazgos…');
    });

    it('muestra el error y el botón de reintento', async () => {
        fiscalFindingStore.loading = false;
        fiscalFindingStore.error = 'No se pudieron cargar los hallazgos.';
        fiscalFindingStore.items = [];

        const wrapper = await mountPage();

        expect(wrapper.text()).toContain('No se pudieron cargar los hallazgos.');
        expect(wrapper.text()).toContain('Reintentar');
    });
});
