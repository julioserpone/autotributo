import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { getJson, patchJson, postJson } = vi.hoisted(() => ({
    getJson: vi.fn(),
    patchJson: vi.fn(),
    postJson: vi.fn(),
}));

vi.mock('@/lib/api', () => ({ getJson, patchJson, postJson }));

import { useFiscalFindingStore } from '@/stores/fiscalFindings';

const finding: import('@/stores/fiscalFindings').FiscalFinding = {
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
        issue_date: '2026-11-16',
        issuer_name: 'PROVEEDOR S.A.',
        issuer_ruc: '1758871873001',
        total_cents: 11500,
    },
    metadata: null,
    note: null,
    resolved_by: null,
    resolved_at: null,
    created_at: '2026-11-20T10:00:00+00:00',
    updated_at: '2026-11-20T10:00:00+00:00',
};

const meta = {
    current_page: 1,
    last_page: 2,
    per_page: 25,
    total: 26,
    counts: {
        total: 26,
        by_status: { open: 20, resolved: 6 },
        by_severity: { critical: 3, warning: 17 },
    },
};

describe('useFiscalFindingStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('carga los hallazgos con contadores', async () => {
        getJson.mockResolvedValueOnce({ data: [finding], meta });

        const store = useFiscalFindingStore();
        await store.fetchFindings();

        expect(getJson).toHaveBeenCalledWith('/api/v1/fiscal-findings?per_page=10&page=1&filter%5Bstatus%5D=open');
        expect(store.items).toHaveLength(1);
        expect(store.counts.by_status.open).toBe(20);
        expect(store.openCount).toBe(20);
        expect(store.criticalCount).toBe(3);
        expect(store.error).toBeNull();
        expect(store.loading).toBe(false);
    });

    it('incluye los filtros activos en la petición', async () => {
        getJson.mockResolvedValueOnce({ data: [], meta });

        const store = useFiscalFindingStore();
        store.filters.companyId = 'company-1';
        store.filters.status = 'all';
        store.filters.severity = 'critical';
        store.filters.code = 'GHOST_COMPANY';
        store.filters.search = 'proveedor';

        await store.fetchFindings(2);

        const url = getJson.mock.calls[0]?.[0] as string;
        const params = new URLSearchParams(url.split('?')[1]);

        expect(params.get('page')).toBe('2');
        expect(params.get('filter[company_id]')).toBe('company-1');
        expect(params.get('filter[status]')).toBeNull();
        expect(params.get('filter[severity]')).toBe('critical');
        expect(params.get('filter[code]')).toBe('GHOST_COMPANY');
        expect(params.get('filter[search]')).toBe('proveedor');
    });

    it('actualiza un hallazgo y refresca el ítem local', async () => {
        const updated = { ...finding, status: 'resolved', note: 'Validado' };
        patchJson.mockResolvedValueOnce({ data: updated });
        getJson.mockResolvedValueOnce({ data: [finding], meta });

        const store = useFiscalFindingStore();
        await store.fetchFindings();
        const result = await store.updateFinding(1, 'resolved', ' Validado ');

        expect(patchJson).toHaveBeenCalledWith('/api/v1/fiscal-findings/1', { status: 'resolved', note: 'Validado' });
        expect(result.status).toBe('resolved');
        expect(store.items[0]?.status).toBe('resolved');
        expect(store.saving).toBe(false);
    });

    it('no envía la nota cuando está vacía', async () => {
        patchJson.mockResolvedValueOnce({ data: finding });

        const store = useFiscalFindingStore();
        await store.updateFinding(1, 'acknowledged', '   ');

        expect(patchJson).toHaveBeenCalledWith('/api/v1/fiscal-findings/1', { status: 'acknowledged' });
    });

    it('dispara la detección manual de una empresa', async () => {
        postJson.mockResolvedValueOnce({ data: { created: 2, updated: 1, resolved: 3, total: 6 } });

        const store = useFiscalFindingStore();
        const result = await store.detect('company-1');

        expect(postJson).toHaveBeenCalledWith('/api/v1/companies/company-1/fiscal-findings/detect', {});
        expect(result).toEqual({ created: 2, updated: 1, resolved: 3, total: 6 });
        expect(store.detecting).toBe(false);
    });

    it('expone el mensaje de error de la API', async () => {
        getJson.mockRejectedValueOnce(new Error('Sin conexión'));

        const store = useFiscalFindingStore();
        await store.fetchFindings();

        expect(store.error).toBe('Sin conexión');
        expect(store.items).toHaveLength(0);
        expect(store.loading).toBe(false);
    });
});
