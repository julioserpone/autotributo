import { computed, ref } from 'vue';

import { defineStore } from 'pinia';

import { getJson } from '@/lib/api';

/** Bloque de IVA de un sentido tributario (`sales`/`purchases`). */
export interface DashboardIvaBucket {
    base_cents: number;
    tax_cents: number;
    documents_count: number;
}

export interface DashboardWithholdings {
    received_cents: number;
    received_documents: number;
    practiced_cents: number;
    practiced_documents: number;
}

export interface DashboardTopProvider {
    issuer_ruc: string;
    issuer_name: string;
    total_cents: number;
    documents_count: number;
}

export interface DashboardFindings {
    open_total: number;
    by_severity: Record<string, number>;
}

export interface DashboardPersonalExpenseCap {
    category_code: string;
    dependents: number;
    cap_cents: number;
}

export interface Dashboard {
    company: {
        public_id: string;
        legal_name: string;
        ruc: string;
    };
    period: {
        year: number;
        month: number;
        status: string | null;
        computed_at: string | null;
    };
    iva: {
        sales: DashboardIvaBucket;
        purchases: DashboardIvaBucket;
        net_cents: number;
    };
    withholdings: DashboardWithholdings;
    top_providers: DashboardTopProvider[];
    findings: DashboardFindings;
    personal_expense_caps: DashboardPersonalExpenseCap[];
}

const emptyBucket: DashboardIvaBucket = { base_cents: 0, tax_cents: 0, documents_count: 0 };

function emptyDashboard(companyId: string, year: number, month: number): Dashboard {
    return {
        company: { public_id: companyId, legal_name: '', ruc: '' },
        period: { year, month, status: null, computed_at: null },
        iva: {
            sales: { ...emptyBucket },
            purchases: { ...emptyBucket },
            net_cents: 0,
        },
        withholdings: {
            received_cents: 0,
            received_documents: 0,
            practiced_cents: 0,
            practiced_documents: 0,
        },
        top_providers: [],
        findings: { open_total: 0, by_severity: {} },
        personal_expense_caps: [],
    };
}

export const useDashboardStore = defineStore('dashboard', () => {
    const dashboard = ref<Dashboard | null>(null);
    const loading = ref(false);
    const error = ref<string | null>(null);
    const year = ref(new Date().getFullYear());
    const month = ref(new Date().getMonth() + 1);

    /** Bloque de IVA y topes anuales para la tarjeta de alertas. */
    const openFindings = computed(() => dashboard.value?.findings.open_total ?? 0);
    const netIvaCents = computed(() => dashboard.value?.iva.net_cents ?? 0);
    const hasActivity = computed(() => {
        const current = dashboard.value;

        if (current === null) return false;

        return current.iva.sales.documents_count > 0
            || current.iva.purchases.documents_count > 0
            || current.withholdings.received_documents > 0
            || current.withholdings.practiced_documents > 0;
    });

    async function fetchDashboard(companyId: string): Promise<void> {
        loading.value = true;
        error.value = null;

        try {
            const response = await getJson<{ data: Dashboard }>(
                `/api/v1/companies/${companyId}/dashboard?year=${year.value}&month=${month.value}`,
            );

            dashboard.value = {
                ...response.data,
                findings: {
                    open_total: response.data.findings.open_total,
                    by_severity: response.data.findings.by_severity ?? {},
                },
                top_providers: response.data.top_providers ?? [],
                personal_expense_caps: response.data.personal_expense_caps ?? [],
            };
        } catch (caught: unknown) {
            dashboard.value = emptyDashboard(companyId, year.value, month.value);
            error.value = caught instanceof Error ? caught.message : 'No se pudo cargar el panel tributario.';
        } finally {
            loading.value = false;
        }
    }

    function setPeriod(nextYear: number, nextMonth: number): void {
        year.value = nextYear;
        month.value = nextMonth;
    }

    function reset(): void {
        dashboard.value = null;
        error.value = null;
        loading.value = false;
    }

    return {
        dashboard,
        loading,
        error,
        year,
        month,
        openFindings,
        netIvaCents,
        hasActivity,
        fetchDashboard,
        setPeriod,
        reset,
    };
});
