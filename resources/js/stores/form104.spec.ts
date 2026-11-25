import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { getJson, postJson } from '@/lib/api';
import { useForm104Store } from '@/stores/form104';

vi.mock('@/lib/api', () => ({ getJson: vi.fn(), postJson: vi.fn() }));

const mockedGetJson = vi.mocked(getJson);
const mockedPostJson = vi.mocked(postJson);

const line = (overrides: Partial<Record<string, unknown>> = {}) => ({
    field: '401',
    line_code: '401',
    section: 'ventas',
    label: 'Ventas locales gravadas tarifa diferente de cero',
    data_type: 'amount',
    is_required: true,
    value_cents: 100_000,
    amount_cents: 100_000,
    base_cents: null,
    rate: null,
    applies_to: 'ventas',
    sources: [],
    requires_signoff: false,
    unavailable: false,
    manual: false,
    note: null,
    ...overrides,
});

const payload = {
    data: {
        company: { public_id: 'company-1', legal_name: 'ACME C.A.', ruc: '1790012345001' },
        period: {
            form_code: '104',
            resolution: 'NAC-DGERCGC16-00000210',
            year: 2026,
            month: 9,
            from: '2026-09-01',
            to: '2026-09-30',
            status: 'open',
            locked_at: null,
            computed_at: '2026-09-30T10:00:00Z',
            has_period: true,
            aggregation_status: 'completed',
            aggregation_error: null,
            aggregated_at: '2026-09-30T10:00:00Z',
        },
        status: {
            draft: true,
            editable: false,
            source: 'period_aggregates',
            requires_signoff: true,
            notice: 'Borrador derivado de los comprobantes importados.',
        },
        lines: [
            line(),
            line({ field: '890', line_code: '890', section: 'pago', manual: true, value_cents: null, unavailable: true }),
        ],
        proportionality: {
            sales_zero: 0,
            sales_general: 100_000,
            proportionality_factor: null,
            proportionality_complement: null,
            purchases_common_tax_to_credit: null,
        },
        previous_periods: [],
        findings: [
            { code: 'TOTALS_MISMATCH', label: 'TOTALS_MISMATCH', severity: 'critical', total: 2 },
        ],
        official_form: {
            form_code: '104',
            resolution: 'NAC-DGERCGC16-00000210',
            sections: [
                { key: 'ventas', title: 'Resumen de ventas y otras operaciones del período' },
                { key: 'pago', title: 'Total consolidado y pago' },
            ],
        },
    },
};

describe('useForm104Store', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        mockedGetJson.mockReset();
        mockedPostJson.mockReset();
    });

    it('carga el borrador del período activo con sus secciones y campos', async () => {
        mockedGetJson.mockResolvedValueOnce(payload);

        const store = useForm104Store();
        store.setPeriod(2026, 9);

        await store.fetchDraft('company-1');

        expect(mockedGetJson).toHaveBeenCalledWith(
            '/api/v1/companies/company-1/form-104?year=2026&month=9',
        );
        expect(store.loading).toBe(false);
        expect(store.error).toBeNull();
        expect(store.draft?.period.resolution).toBe('NAC-DGERCGC16-00000210');
        expect(store.sections).toHaveLength(2);
        expect(store.lines).toHaveLength(2);
        expect(store.computedFields).toBe(1);
        expect(store.pendingFields).toBe(1);
        expect(store.blockingFindings).toBe(2);
        expect(store.hasPeriod).toBe(true);
    });

    it('expone el error y vacía el borrador cuando la petición falla', async () => {
        mockedGetJson.mockRejectedValueOnce(new Error('Servicio no disponible'));

        const store = useForm104Store();

        await store.fetchDraft('company-1');

        expect(store.error).toBe('Servicio no disponible');
        expect(store.draft).toBeNull();
        expect(store.lines).toEqual([]);
        expect(store.blockingFindings).toBe(0);
    });

    it('reset limpia el estado', async () => {
        mockedGetJson.mockResolvedValueOnce(payload);

        const store = useForm104Store();
        await store.fetchDraft('company-1');

        store.reset();

        expect(store.draft).toBeNull();
        expect(store.error).toBeNull();
        expect(store.loading).toBe(false);
        expect(store.recomputing).toBe(false);
    });

    it('recalcula los agregados del período activo (F5-07d)', async () => {
        mockedPostJson.mockResolvedValueOnce({ data: [] });

        const store = useForm104Store();
        store.setPeriod(2026, 9);

        await expect(store.recompute('company-1')).resolves.toBe(true);

        expect(mockedPostJson).toHaveBeenCalledWith(
            '/api/v1/companies/company-1/period-aggregates/recompute',
            { year: 2026, month: 9 },
        );
        expect(store.recomputing).toBe(false);
        expect(store.error).toBeNull();
    });

    it('expone el error cuando el recálculo falla', async () => {
        mockedPostJson.mockRejectedValueOnce(new Error('La agregación falló'));

        const store = useForm104Store();

        await expect(store.recompute('company-1')).resolves.toBe(false);

        expect(store.error).toBe('La agregación falló');
        expect(store.recomputing).toBe(false);
    });
});
