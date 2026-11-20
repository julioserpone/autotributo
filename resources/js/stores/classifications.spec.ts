import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { getJson, patchJson, postJson } = vi.hoisted(() => ({
    getJson: vi.fn(),
    patchJson: vi.fn(),
    postJson: vi.fn(),
}));

vi.mock('@/lib/api', () => ({ getJson, patchJson, postJson }));

import { useClassificationStore } from '@/stores/classifications';

const item: import('@/stores/classifications').ClassificationApi = {
    id: 1,
    status: 'needs_review',
    method: 'llm',
    confidence: 0.85,
    prompt_version: 'v1',
    expense_category: { id: 2, code: 'alimentacion', name: 'Alimentación' },
    item: { id: 10, line_number: 1, description: 'COMPRA DE INSUMOS', total_cents: 11500 },
    fiscal_document: {
        id: 'doc-1',
        document_type: '01',
        issue_date: '2026-09-15',
        issuer_name: 'PROVEEDOR S.A.',
        issuer_ruc: '1758871873001',
        total_cents: 11500,
    },
    reviewed_by: null,
    reviewed_at: null,
    created_at: null,
    updated_at: null,
};

describe('useClassificationStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('carga la cola y limpia la selección', async () => {
        getJson.mockResolvedValueOnce({ data: [item], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } });

        const store = useClassificationStore();
        await store.fetchQueue();

        expect(store.items).toHaveLength(1);
        expect(store.loading).toBe(false);
        expect(store.error).toBeNull();
    });

    it('acepta una clasificación y actualiza el ítem', async () => {
        const updated = { ...item, status: 'accepted' };
        patchJson.mockResolvedValueOnce({ data: updated });
        getJson.mockResolvedValueOnce({ data: [item], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } });

        const store = useClassificationStore();
        await store.fetchQueue();
        await store.accept(1, 2);

        expect(patchJson).toHaveBeenCalledWith('/api/v1/classifications/1', { decision: 'accept', expense_category_id: 2 });
        expect(store.items[0]?.status).toBe('accepted');
    });

    it('rechaza una clasificación', async () => {
        const updated = { ...item, status: 'rejected' };
        patchJson.mockResolvedValueOnce({ data: updated });
        getJson.mockResolvedValueOnce({ data: [item], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } });

        const store = useClassificationStore();
        await store.fetchQueue();
        await store.reject(1);

        expect(patchJson).toHaveBeenCalledWith('/api/v1/classifications/1', { decision: 'reject' });
    });

    it('revisa en bloque y recarga la cola', async () => {
        postJson.mockResolvedValueOnce({ data: { updated: 2, failed: [] } });
        getJson.mockResolvedValueOnce({ data: [item], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } });
        getJson.mockResolvedValueOnce({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } });

        const store = useClassificationStore();
        await store.fetchQueue();
        const result = await store.bulkReview([1, 2], 'accept', 2);

        expect(postJson).toHaveBeenCalledWith('/api/v1/classifications/bulk-review', { ids: [1, 2], decision: 'accept', expense_category_id: 2 });
        expect(result.updated).toBe(2);
    });

    it('alterna la selección', () => {
        const store = useClassificationStore();
        store.toggleSelection(1);
        expect(store.selectedIds.has(1)).toBe(true);
        store.toggleSelection(1);
        expect(store.selectedIds.has(1)).toBe(false);
    });
});