import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { getJson } = vi.hoisted(() => ({ getJson: vi.fn() }));

vi.mock('@/lib/api', () => ({ getJson, ApiError: class ApiError extends Error { status: number; constructor(status: number, message: string) { super(message); this.status = status; } } }));

import { ApiError } from '@/lib/api';
import { useCompanyStore } from '@/stores/company';

const companiesPayload = [
    { id: 'c1', ruc: '1790016919001', legal_name: 'Empresa Uno', trade_name: 'Uno', regime: 'general', regime_label: 'Régimen general', status: 'active', is_withholding_agent: false },
    { id: 'c2', ruc: '1791251237001', legal_name: 'Empresa Dos', trade_name: null, regime: 'general', regime_label: 'Régimen general', status: 'active', is_withholding_agent: false },
];

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
        expect(store.activeCompany?.id).toBe('c1');
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
        store.companies = companiesPayload as never;
        store.setActive('c2');

        expect(store.activeId).toBe('c2');
        expect(window.localStorage.getItem('autotributo.company')).toBe('c2');
    });
});
