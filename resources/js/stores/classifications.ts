import { computed, ref } from 'vue';

import { defineStore } from 'pinia';

import { getJson, patchJson, postJson } from '@/lib/api';

export type ClassificationStatus = 'suggested' | 'accepted' | 'rejected' | 'needs_review' | 'auto' | 'manual' | 'pending';

export interface ClassificationExpenseCategory {
    id: number;
    code: string;
    name: string;
}

export interface ClassificationItem {
    id: number;
    line_number: number;
    description: string;
    total_cents: number;
}

export interface ClassificationFiscalDocument {
    id: string;
    document_type: string;
    issue_date: string | null;
    issuer_name: string;
    issuer_ruc: string;
    total_cents: number;
}

export interface ClassificationApi {
    id: number;
    status: string;
    method: string;
    confidence: number;
    prompt_version: string | null;
    expense_category: ClassificationExpenseCategory | null;
    item: ClassificationItem | null;
    fiscal_document: ClassificationFiscalDocument | null;
    reviewed_by: { id: number; name: string } | null;
    reviewed_at: string | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface ClassificationFilters {
    status: string;
    method: string;
    companyId: string;
    search: string;
    reviewed: string;
}

export const methodLabels: Record<string, string> = {
    rule: 'Regla',
    cache: 'Caché',
    llm: 'IA',
    manual: 'Manual',
};

export const statusLabels: Record<string, string> = {
    needs_review: 'Necesita revisión',
    suggested: 'Sugerida',
    accepted: 'Aceptada',
    rejected: 'Rechazada',
    auto: 'Automática',
    manual: 'Manual',
    pending: 'Pendiente',
};

export function confidenceColor(confidence: number): 'success' | 'warning' | 'error' {
    if (confidence >= 0.9) return 'success';
    if (confidence >= 0.7) return 'warning';
    return 'error';
}

export const useClassificationStore = defineStore('classifications', () => {
    const items = ref<ClassificationApi[]>([]);
    const loading = ref(false);
    const error = ref<string | null>(null);
    const selectedIds = ref<Set<number>>(new Set());

    const filters = ref<ClassificationFilters>({
        status: 'needs_review',
        method: 'all',
        companyId: 'all',
        search: '',
        reviewed: 'all',
    });

    const meta = ref<{ current_page: number; last_page: number; per_page: number; total: number } | null>(null);

    function buildQuery(page: number): string {
        const params = new URLSearchParams();
        params.set('per_page', '25');
        params.set('page', String(page));
        if (filters.value.status && filters.value.status !== 'all') params.set('status', filters.value.status);
        if (filters.value.method && filters.value.method !== 'all') params.set('method', filters.value.method);
        if (filters.value.companyId && filters.value.companyId !== 'all') params.set('company_id', filters.value.companyId);
        if (filters.value.search.trim()) params.set('search', filters.value.search.trim());
        if (filters.value.reviewed && filters.value.reviewed !== 'all') params.set('reviewed', filters.value.reviewed);
        return `/api/v1/classifications?${params.toString()}`;
    }

    async function fetchQueue(page = 1): Promise<void> {
        loading.value = true;
        error.value = null;

        try {
            const response = await getJson<{ data: ClassificationApi[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }>(buildQuery(page));
            items.value = response.data;
            meta.value = response.meta ?? null;
            selectedIds.value = new Set();
        } catch (caught: unknown) {
            error.value = caught instanceof Error ? caught.message : 'No se pudieron cargar las clasificaciones.';
        } finally {
            loading.value = false;
        }
    }

    function toggleSelection(id: number): void {
        const next = new Set(selectedIds.value);
        if (next.has(id)) {
            next.delete(id);
        } else {
            next.add(id);
        }
        selectedIds.value = next;
    }

    function clearSelection(): void {
        selectedIds.value = new Set();
    }

    async function reviewOne(id: number, decision: 'accept' | 'reject', expenseCategoryId?: number): Promise<ClassificationApi | null> {
        try {
            const body: Record<string, unknown> = { decision };
            if (expenseCategoryId !== undefined) body.expense_category_id = expenseCategoryId;
            const response = await patchJson<{ data: ClassificationApi }>(`/api/v1/classifications/${id}`, body);
            const updated = response.data;
            const index = items.value.findIndex((item) => item.id === id);
            if (index !== -1) {
                items.value[index] = updated;
            }
            return updated;
        } catch (caught: unknown) {
            error.value = caught instanceof Error ? caught.message : 'No se pudo revisar la clasificación.';
            return null;
        }
    }

    async function accept(id: number, expenseCategoryId?: number): Promise<void> {
        await reviewOne(id, 'accept', expenseCategoryId);
    }

    async function reject(id: number): Promise<void> {
        await reviewOne(id, 'reject');
    }

    async function bulkReview(ids: number[], decision: 'accept' | 'reject', expenseCategoryId?: number): Promise<{ updated: number; failed: { id: number; reason: string }[] }> {
        const body: Record<string, unknown> = { ids, decision };
        if (expenseCategoryId !== undefined) body.expense_category_id = expenseCategoryId;
        try {
            const response = await postJson<{ data: { updated: number; failed: { id: number; reason: string }[] } }>('/api/v1/classifications/bulk-review', body);
            await fetchQueue(meta.value?.current_page ?? 1);
            return response.data;
        } catch (caught: unknown) {
            error.value = caught instanceof Error ? caught.message : 'No se pudo revisar en bloque.';
            return { updated: 0, failed: [] };
        }
    }

    const selectedCount = computed(() => selectedIds.value.size);

    return {
        items,
        loading,
        error,
        selectedIds,
        selectedCount,
        filters,
        meta,
        fetchQueue,
        toggleSelection,
        clearSelection,
        accept,
        reject,
        bulkReview,
    };
});