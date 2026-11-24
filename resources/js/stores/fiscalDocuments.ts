import { computed, ref } from 'vue';

import { defineStore } from 'pinia';

import { getJson } from '@/lib/api';

export type FiscalDocumentStatus = 'authorized' | 'pending' | 'rejected';

export interface FiscalDocumentApi {
    id: string;
    number: string;
    document_type: string;
    access_key: string;
    authorization_number: string | null;
    issue_date: string | null;
    authorization_date: string | null;
    status: string;
    environment: string;
    direction: string;
    issuer_ruc: string;
    issuer_name: string;
    receiver_ruc: string;
    receiver_name: string;
    subtotal_cents: number;
    discount_cents: number;
    tax_cents: number;
    ice_cents: number;
    irbpnr_cents: number;
    tip_cents: number;
    total_cents: number;
    currency: string;
    source: string;
    totals_mismatch: boolean;
}

/** Clasificación de una línea del comprobante (F3-05). */
export interface FiscalDocumentItemClassification {
    status: string;
    expense_category_id: number | null;
    expense_category: { id: number; code: string; name: string } | null;
    method: string | null;
    confidence: number | null;
    prompt_version: string | null;
}

/** Línea de detalle del comprobante con su clasificación. */
export interface FiscalDocumentItemApi {
    line_number: number;
    description: string;
    main_code: string | null;
    auxiliary_code: string | null;
    quantity: string;
    unit_price: string;
    discount: string;
    subtotal_cents: number;
    total_cents: number;
    classification: FiscalDocumentItemClassification;
}

/** Comprobante con el detalle completo (solo lo devuelve `GET /{id}`). */
export interface FiscalDocumentDetailApi extends FiscalDocumentApi {
    items: FiscalDocumentItemApi[];
}

export interface FiscalDocumentFilters {
    status: string;
    documentType: string;
    companyId: string;
    issuer: string;
    dateFrom: string;
    dateTo: string;
}

export const documentTypeLabels: Record<string, string> = {
    '01': 'Factura',
    '03': 'Liquidación de compra',
    '04': 'Nota de crédito',
    '05': 'Nota de débito',
    '06': 'Guía de remisión',
    '07': 'Comprobante de retención',
};

function toStatus(value: string): FiscalDocumentStatus {
    if (value === 'pending') return 'pending';
    if (value === 'rejected') return 'rejected';
    return 'authorized';
}

export function toFiscalDocumentRow(fiscal_document: FiscalDocumentApi): import('@/components/fiscal-documents/FiscalDocumentTable.vue').FiscalDocumentRow {
    return {
        id: fiscal_document.id,
        number: fiscal_document.number,
        documentType: documentTypeLabels[fiscal_document.document_type] ?? fiscal_document.document_type,
        issuerName: fiscal_document.issuer_name,
        issuerRuc: fiscal_document.issuer_ruc,
        issueDate: fiscal_document.issue_date
            ? new Date(fiscal_document.issue_date).toLocaleDateString('es-EC', { day: '2-digit', month: 'short', year: 'numeric' })
            : '—',
        subtotalCents: fiscal_document.subtotal_cents,
        taxCents: fiscal_document.tax_cents,
        totalCents: fiscal_document.total_cents,
        status: toStatus(fiscal_document.status),
    };
}

export const useFiscalDocumentStore = defineStore('fiscal_documents', () => {
    const fiscalDocuments = ref<FiscalDocumentApi[]>([]);
    const loading = ref(false);
    const loadingMore = ref(false);
    const error = ref<string | null>(null);
    const nextCursor = ref<string | null>(null);

    const filters = ref<FiscalDocumentFilters>({
        status: 'all',
        documentType: 'all',
        companyId: 'all',
        issuer: '',
        dateFrom: '',
        dateTo: '',
    });

    const rows = computed(() => fiscalDocuments.value.map(toFiscalDocumentRow));
    const detail = ref<FiscalDocumentDetailApi | null>(null);
    const detailLoading = ref(false);
    const detailError = ref<string | null>(null);

    function buildQuery(cursor: string | null): string {
        const params = new URLSearchParams();
        params.set('per_page', '25');
        if (cursor) params.set('cursor', cursor);
        if (filters.value.status && filters.value.status !== 'all') params.set('status', filters.value.status);
        if (filters.value.documentType && filters.value.documentType !== 'all') params.set('document_type', filters.value.documentType);
        if (filters.value.companyId && filters.value.companyId !== 'all') params.set('company_id', filters.value.companyId);
        if (filters.value.issuer.trim()) params.set('issuer', filters.value.issuer.trim());
        if (filters.value.dateFrom) params.set('date_from', filters.value.dateFrom);
        if (filters.value.dateTo) params.set('date_to', filters.value.dateTo);
        return `/api/v1/fiscal_documents?${params.toString()}`;
    }

    async function fetchFirst(): Promise<void> {
        loading.value = true;
        error.value = null;
        fiscalDocuments.value = [];
        nextCursor.value = null;

        try {
            const response = await getJson<{ data: FiscalDocumentApi[]; meta: { next_cursor: string | null } }>(buildQuery(null));
            fiscalDocuments.value = response.data;
            nextCursor.value = response.meta?.next_cursor ?? null;
        } catch (caught: unknown) {
            error.value = caught instanceof Error ? caught.message : 'No se pudieron cargar los comprobantes.';
        } finally {
            loading.value = false;
        }
    }

    async function loadMore(): Promise<void> {
        if (!nextCursor.value || loadingMore.value) return;
        loadingMore.value = true;

        try {
            const response = await getJson<{ data: FiscalDocumentApi[]; meta: { next_cursor: string | null } }>(buildQuery(nextCursor.value));
            fiscalDocuments.value = [...fiscalDocuments.value, ...response.data];
            nextCursor.value = response.meta?.next_cursor ?? null;
        } catch (caught: unknown) {
            error.value = caught instanceof Error ? caught.message : 'No se pudieron cargar más comprobantes.';
        } finally {
            loadingMore.value = false;
        }
    }

    /**
     * Detalle de un comprobante con sus líneas y la clasificación de cada una.
     *
     * El listado no trae ítems a propósito (sería pesado con cientos de filas),
     * así que el drawer los pide al abrirse.
     */
    async function fetchDetail(id: string): Promise<FiscalDocumentDetailApi | null> {
        detailLoading.value = true;
        detailError.value = null;

        try {
            const response = await getJson<{ data: FiscalDocumentDetailApi }>(`/api/v1/fiscal_documents/${id}`);
            detail.value = response.data;

            return response.data;
        } catch (caught: unknown) {
            detailError.value = caught instanceof Error ? caught.message : 'No se pudo cargar el detalle.';

            return null;
        } finally {
            detailLoading.value = false;
        }
    }

    function clearDetail(): void {
        detail.value = null;
        detailError.value = null;
    }

    function setFilter(patch: Partial<FiscalDocumentFilters>): void {
        filters.value = { ...filters.value, ...patch };
    }

    return {
        fiscalDocuments,
        loading,
        loadingMore,
        error,
        nextCursor,
        filters,
        rows,
        detail,
        detailLoading,
        detailError,
        fetchFirst,
        loadMore,
        fetchDetail,
        clearDetail,
        setFilter,
    };
});
