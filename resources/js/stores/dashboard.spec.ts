import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { getJson } from '@/lib/api';
import { useDashboardStore } from '@/stores/dashboard';

vi.mock('@/lib/api', () => ({ getJson: vi.fn() }));

const mockedGetJson = vi.mocked(getJson);

const payload = {
    data: {
        company: { public_id: 'company-1', legal_name: 'ACME C.A.', ruc: '1790012345001' },
        period: { year: 2026, month: 9, status: 'open', computed_at: '2026-09-30T10:00:00Z' },
        iva: {
            sales: { base_cents: 10_000, tax_cents: 1_500, documents_count: 1 },
            purchases: { base_cents: 5_000, tax_cents: 750, documents_count: 2 },
            net_cents: 750,
        },
        withholdings: {
            received_cents: 300,
            received_documents: 1,
            practiced_cents: 200,
            practiced_documents: 1,
        },
        top_providers: [
            { issuer_ruc: '1790012345001', issuer_name: 'Proveedor', total_cents: 8_050, documents_count: 2 },
        ],
        findings: { open_total: 2, by_severity: { warning: 1, critical: 1 } },
        personal_expense_caps: [{ category_code: 'global', dependents: 0, cap_cents: 1_900_000 }],
    },
};

describe('useDashboardStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        mockedGetJson.mockReset();
    });

    it('carga el panel del período activo y expone el IVA neto', async () => {
        mockedGetJson.mockResolvedValueOnce(payload);

        const store = useDashboardStore();
        store.setPeriod(2026, 9);

        await store.fetchDashboard('company-1');

        expect(mockedGetJson).toHaveBeenCalledWith(
            '/api/v1/companies/company-1/dashboard?year=2026&month=9',
        );
        expect(store.loading).toBe(false);
        expect(store.error).toBeNull();
        expect(store.dashboard?.company.legal_name).toBe('ACME C.A.');
        expect(store.netIvaCents).toBe(750);
        expect(store.openFindings).toBe(2);
        expect(store.hasActivity).toBe(true);
    });

    it('deja el panel en cero y expone el error cuando la petición falla', async () => {
        mockedGetJson.mockRejectedValueOnce(new Error('Servicio no disponible'));

        const store = useDashboardStore();
        store.setPeriod(2026, 4);

        await store.fetchDashboard('company-1');

        expect(store.error).toBe('Servicio no disponible');
        expect(store.netIvaCents).toBe(0);
        expect(store.openFindings).toBe(0);
        expect(store.hasActivity).toBe(false);
        expect(store.dashboard?.period.month).toBe(4);
    });

    it('normaliza respuestas sin proveedores ni topes', async () => {
        mockedGetJson.mockResolvedValueOnce({
            data: {
                ...payload.data,
                top_providers: undefined,
                personal_expense_caps: undefined,
                findings: { open_total: 0 },
            },
        });

        const store = useDashboardStore();

        await store.fetchDashboard('company-1');

        expect(store.dashboard?.top_providers).toEqual([]);
        expect(store.dashboard?.personal_expense_caps).toEqual([]);
        expect(store.dashboard?.findings.by_severity).toEqual({});
    });

    it('reset limpia el panel y el error', async () => {
        mockedGetJson.mockRejectedValueOnce(new Error('falló'));

        const store = useDashboardStore();
        await store.fetchDashboard('company-1');

        store.reset();

        expect(store.dashboard).toBeNull();
        expect(store.error).toBeNull();
    });
});
