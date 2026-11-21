import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { getJson, postJson, patchJson, deleteJson } = vi.hoisted(() => ({
    getJson: vi.fn(),
    postJson: vi.fn(),
    patchJson: vi.fn(),
    deleteJson: vi.fn(),
}));

vi.mock('@/lib/api', () => ({
    getJson,
    postJson,
    patchJson,
    deleteJson,
    ApiError: class ApiError extends Error {
        status: number;
        constructor(status: number, message: string) {
            super(message);
            this.status = status;
        }
    },
}));

import { ApiError } from '@/lib/api';
import { useCompanyStore } from '@/stores/company';
import type { Company } from '@/stores/company';

const baseCompany: Company = {
    id: 'c1',
    ruc: '1790016919001',
    legal_name: 'Empresa Uno',
    trade_name: 'Uno',
    regime: 'general',
    regime_label: 'Régimen general',
    is_accounting_required: true,
    is_withholding_agent: false,
    withholding_resolution: null,
    economic_activity_code: null,
    address: null,
    email: null,
    phone: null,
    fiscal_year_start_month: 1,
    status: 'active',
    settings: null,
    created_at: null,
    updated_at: null,
};

const companiesPayload: Company[] = [
    baseCompany,
    {
        ...baseCompany,
        id: 'c2',
        ruc: '1791251237001',
        legal_name: 'Empresa Dos',
        trade_name: null,
        is_accounting_required: false,
    },
];

function makeCompany(overrides: Partial<Company>): Company {
    return { ...baseCompany, ...overrides };
}

describe('useCompanyStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        window.localStorage.clear();
    });

    it('carga las empresas y selecciona la primera', async () => {
        getJson.mockResolvedValueOnce({ data: companiesPayload });

        const store = useCompanyStore();
        await store.fetchCompanies();

        expect(store.companies).toHaveLength(2);
        expect(store.companies.map((company) => company.id)).toEqual(['c2', 'c1']);
        expect(store.activeCompany?.id).toBe('c2');
        expect(store.hasCompany).toBe(true);
    });

    it('marca requiresTwoFactorSetup cuando la API responde 403', async () => {
        getJson.mockRejectedValueOnce(new ApiError(403, 'Forbidden'));

        const store = useCompanyStore();
        await store.fetchCompanies();

        expect(store.companies).toHaveLength(0);
        expect(store.requiresTwoFactorSetup).toBe(true);
        expect(store.error).toBeTruthy();
    });

    it('permite cambiar la empresa activa y la persiste', () => {
        const store = useCompanyStore();
        store.companies = companiesPayload;
        store.setActive('c2');

        expect(store.activeId).toBe('c2');
        expect(window.localStorage.getItem('autotributo.company')).toBe('c2');
    });

    it('crea una empresa, la ordena por razón social y la deja activa', async () => {
        postJson.mockResolvedValueOnce({ data: makeCompany({ id: 'c3', legal_name: 'Empresa Cero' }) });

        const store = useCompanyStore();
        store.companies = companiesPayload;

        const created = await store.createCompany({ ruc: '1790016919001', legal_name: 'Empresa Cero' });

        expect(postJson).toHaveBeenCalledWith('/api/v1/companies', {
            ruc: '1790016919001',
            legal_name: 'Empresa Cero',
        });
        expect(created.id).toBe('c3');
        expect(store.companies.map((company) => company.legal_name)).toEqual([
            'Empresa Cero',
            'Empresa Dos',
            'Empresa Uno',
        ]);
        expect(store.activeId).toBe('c3');
    });

    it('actualiza una empresa sin duplicarla en la lista', async () => {
        patchJson.mockResolvedValueOnce({ data: makeCompany({ legal_name: 'Empresa Uno S.A.' }) });

        const store = useCompanyStore();
        store.companies = companiesPayload;
        store.setActive('c1');

        await store.updateCompany('c1', { legal_name: 'Empresa Uno S.A.' });

        expect(patchJson).toHaveBeenCalledWith('/api/v1/companies/c1', { legal_name: 'Empresa Uno S.A.' });
        expect(store.companies).toHaveLength(2);
        expect(store.companies[1]?.legal_name).toBe('Empresa Uno S.A.');
        expect(store.activeId).toBe('c1');
    });

    it('archiva la empresa activa y selecciona la siguiente disponible', async () => {
        deleteJson.mockResolvedValueOnce(undefined);

        const store = useCompanyStore();
        store.companies = companiesPayload;
        store.setActive('c1');

        await store.archiveCompany('c1');

        expect(deleteJson).toHaveBeenCalledWith('/api/v1/companies/c1');
        expect(store.companies.map((company) => company.id)).toEqual(['c2']);
        expect(store.activeId).toBe('c2');
        expect(window.localStorage.getItem('autotributo.company')).toBe('c2');
    });

    it('archivar la última empresa deja la selección vacía', async () => {
        deleteJson.mockResolvedValueOnce(undefined);

        const store = useCompanyStore();
        store.companies = [baseCompany];
        store.setActive('c1');

        await store.archiveCompany('c1');

        expect(store.companies).toHaveLength(0);
        expect(store.activeId).toBeNull();
        expect(store.hasCompany).toBe(false);
        expect(window.localStorage.getItem('autotributo.company')).toBeNull();
    });

    it('archivar una empresa que no está activa conserva la selección', async () => {
        deleteJson.mockResolvedValueOnce(undefined);

        const store = useCompanyStore();
        store.companies = companiesPayload;
        store.setActive('c1');

        await store.archiveCompany('c2');

        expect(store.activeId).toBe('c1');
        expect(store.companies.map((company) => company.id)).toEqual(['c1']);
    });

    it('recupera el detalle de una empresa y lo incorpora a la lista', async () => {
        getJson.mockResolvedValueOnce({ data: makeCompany({ id: 'c9', legal_name: 'Empresa Nueve' }) });

        const store = useCompanyStore();
        store.companies = companiesPayload;

        const company = await store.fetchCompany('c9');

        expect(getJson).toHaveBeenCalledWith('/api/v1/companies/c9');
        expect(company.id).toBe('c9');
        expect(store.companies.map((item) => item.id)).toEqual(['c2', 'c9', 'c1']);
    });

    it('propaga el error de validación al formulario', async () => {
        const failure = new ApiError(422, 'The given data was invalid.');
        postJson.mockRejectedValueOnce(failure);

        const store = useCompanyStore();

        await expect(store.createCompany({ ruc: '123', legal_name: '' })).rejects.toBe(failure);
        expect(store.companies).toHaveLength(0);
    });
});
