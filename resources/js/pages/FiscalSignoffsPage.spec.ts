import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import FiscalSignoffsPage from '@/pages/FiscalSignoffsPage.vue';

const stages = [
    {
        key: 'iva-and-retention-catalogs',
        version: 1,
        title: 'Catálogos de tarifas y retenciones',
        summary: 'Validar vigencias.',
        references: ['FiscalCatalogData.php'],
        fingerprint: 'fingerprint-1',
        status: 'pending',
        latest_review: null,
        history: [],
    },
    {
        key: 'ats-iva-nature-mapping',
        version: 1,
        title: 'Naturaleza IVA por código ATS',
        summary: 'Validar la relación entre códigos y naturaleza.',
        references: ['Ficha Técnica ATS'],
        fingerprint: 'fingerprint-2',
        status: 'approved',
        latest_review: {
            stage_key: 'ats-iva-nature-mapping',
            stage_version: 1,
            stage_fingerprint: 'fingerprint-2',
            decision: 'approved',
            comments: 'Revisado.',
            reviewed_by: { name: 'Contadora' },
            reviewed_at: '2026-11-25T18:00:00+00:00',
        },
        history: [],
    },
];

const store = {
    stages,
    loading: false,
    savingStage: null as string | null,
    error: null as string | null,
    fetchStages: vi.fn(),
    review: vi.fn(),
};

const authStore = {
    user: { memberships: [{ tenant_id: 1, role: 'accountant' }] },
};

vi.mock('@/stores/fiscalSignoffs', () => ({
    useFiscalSignoffStore: () => store,
}));

vi.mock('@/stores/auth', () => ({
    useAuthStore: () => authStore,
}));

vi.mock('@nuxt/ui/composables/useToast', () => ({
    useToast: () => ({ add: vi.fn() }),
}));

function mountPage() {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [{ path: '/fiscal-signoffs', name: 'fiscal-signoffs', component: FiscalSignoffsPage }],
    });

    return router.push('/fiscal-signoffs').then(() =>
        mount(FiscalSignoffsPage, {
            global: {
                plugins: [router],
                stubs: {
                    AppShell: { template: '<div><slot /></div>' },
                    AppPage: {
                        props: ['title', 'description'],
                        template: '<main><h1>{{ title }}</h1><p>{{ description }}</p><slot /></main>',
                    },
                },
            },
        }),
    );
}

describe('FiscalSignoffsPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        store.stages = stages;
        store.loading = false;
        store.savingStage = null;
        store.error = null;
        authStore.user.memberships[0]!.role = 'accountant';
    });

    it('muestra las etapas, el estado, el historial y el aviso legal', async () => {
        const wrapper = await mountPage();

        expect(wrapper.text()).toContain('Validación contable');
        expect(wrapper.text()).toContain('Naturaleza IVA por código ATS');
        expect(wrapper.text()).toContain('Aprobado');
        expect(wrapper.text()).toContain('Revisado.');
        expect(wrapper.text()).toContain('no constituye una firma electrónica');
        expect(wrapper.text()).toContain('Dar OK');
        expect(wrapper.text()).toContain('Solicitar cambios');
    });

    it('oculta las acciones para roles que solo pueden consultar', async () => {
        authStore.user.memberships[0]!.role = 'viewer';

        const wrapper = await mountPage();

        expect(wrapper.text()).toContain('Tu rol puede consultar las validaciones');
        expect(wrapper.text()).not.toContain('Dar OK');
    });

    it('presenta los estados de carga y error', async () => {
        store.loading = true;
        store.stages = [];

        const loadingWrapper = await mountPage();
        expect(loadingWrapper.text()).toContain('Cargando etapas de validación');

        store.loading = false;
        store.error = 'No se pudieron cargar las validaciones.';

        const errorWrapper = await mountPage();
        expect(errorWrapper.text()).toContain('No se pudieron cargar las validaciones.');
        expect(errorWrapper.text()).toContain('Reintentar');
    });
});
