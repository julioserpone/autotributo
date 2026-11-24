import { computed, ref } from 'vue';

import { defineStore } from 'pinia';

import { getJson, patchJson, postJson } from '@/lib/api';

export interface FiscalFindingCompany {
    id: string;
    ruc: string;
    legal_name: string;
}

export interface FiscalFindingSource {
    type: string;
    id: string;
    document_type: string;
    number: string;
    access_key: string;
    issue_date: string | null;
    issuer_name: string;
    issuer_ruc: string;
    subtotal_cents: number;
    tax_cents: number;
    total_cents: number;
}

export interface FiscalFinding {
    id: number;
    code: string;
    severity: string;
    status: string;
    title: string;
    description: string;
    company: FiscalFindingCompany | null;
    source_type: string;
    source: FiscalFindingSource | null;
    metadata: Record<string, unknown> | null;
    note: string | null;
    resolved_by: { id: number; name: string } | null;
    resolved_at: string | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface FiscalFindingCounts {
    total: number;
    by_status: Record<string, number>;
    by_severity: Record<string, number>;
}

export interface FiscalFindingFilters {
    companyId: string;
    status: string;
    severity: string;
    code: string;
    search: string;
}

export interface DetectFindingsResult {
    created: number;
    updated: number;
    resolved: number;
    total: number;
}

interface FiscalFindingMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    counts?: FiscalFindingCounts;
}

const emptyCounts: FiscalFindingCounts = {
    total: 0,
    by_status: {},
    by_severity: {},
};

export const useFiscalFindingStore = defineStore('fiscal_findings', () => {
    const items = ref<FiscalFinding[]>([]);
    const loading = ref(false);
    const saving = ref(false);
    const detecting = ref(false);
    const error = ref<string | null>(null);
    const meta = ref<FiscalFindingMeta | null>(null);
    const counts = ref<FiscalFindingCounts>({ ...emptyCounts });

    const filters = ref<FiscalFindingFilters>({
        companyId: 'all',
        status: 'open',
        severity: 'all',
        code: 'all',
        search: '',
    });

    const openCount = computed(() => counts.value.by_status.open ?? 0);
    const criticalCount = computed(() => counts.value.by_severity.critical ?? 0);
    const warningCount = computed(() => counts.value.by_severity.warning ?? 0);
    const resolvedCount = computed(() => counts.value.by_status.resolved ?? 0);

    function buildQuery(page: number): string {
        const params = new URLSearchParams();
        params.set('per_page', '10');
        params.set('page', String(page));

        if (filters.value.companyId && filters.value.companyId !== 'all') {
            params.set('filter[company_id]', filters.value.companyId);
        }
        if (filters.value.status && filters.value.status !== 'all') {
            params.set('filter[status]', filters.value.status);
        }
        if (filters.value.severity && filters.value.severity !== 'all') {
            params.set('filter[severity]', filters.value.severity);
        }
        if (filters.value.code && filters.value.code !== 'all') {
            params.set('filter[code]', filters.value.code);
        }
        if (filters.value.search.trim()) {
            params.set('filter[search]', filters.value.search.trim());
        }

        return `/api/v1/fiscal-findings?${params.toString()}`;
    }

    async function fetchFindings(page = 1): Promise<void> {
        loading.value = true;
        error.value = null;

        try {
            const response = await getJson<{ data: FiscalFinding[]; meta: FiscalFindingMeta }>(buildQuery(page));
            items.value = response.data ?? [];
            meta.value = response.meta ?? null;
            counts.value = response.meta?.counts ?? { ...emptyCounts };
        } catch (caught: unknown) {
            error.value = caught instanceof Error ? caught.message : 'No se pudieron cargar los hallazgos.';
        } finally {
            loading.value = false;
        }
    }

    async function updateFinding(id: number, status: string, note?: string): Promise<FiscalFinding> {
        saving.value = true;

        try {
            const response = await patchJson<{ data: FiscalFinding }>(`/api/v1/fiscal-findings/${id}`, {
                status,
                ...(note !== undefined && note.trim() !== '' ? { note: note.trim() } : {}),
            });

            const index = items.value.findIndex((item) => item.id === response.data.id);

            if (index !== -1) {
                items.value[index] = response.data;
            }

            return response.data;
        } finally {
            saving.value = false;
        }
    }

    async function detect(companyId: string): Promise<DetectFindingsResult> {
        detecting.value = true;

        try {
            const response = await postJson<{ data: DetectFindingsResult }>(
                `/api/v1/companies/${companyId}/fiscal-findings/detect`,
                {},
            );

            return response.data;
        } finally {
            detecting.value = false;
        }
    }

    function reset(): void {
        items.value = [];
        meta.value = null;
        counts.value = { ...emptyCounts };
        error.value = null;
    }

    return {
        items,
        loading,
        saving,
        detecting,
        error,
        meta,
        counts,
        filters,
        openCount,
        criticalCount,
        warningCount,
        resolvedCount,
        fetchFindings,
        updateFinding,
        detect,
        reset,
    };
});
