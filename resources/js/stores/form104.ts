import { computed, ref } from 'vue';

import { defineStore } from 'pinia';

import { getJson, postJson } from '@/lib/api';

/**
 * Borrador del Formulario 104 (F4-09).
 *
 * El backend devuelve todos los campos del formulario oficial en su orden, con
 * el valor calculado (o `null` cuando el campo no tiene fuente todavía), la
 * marca de `requires_signoff`, la de `manual` y las fuentes para el drill-down.
 */
export interface Form104Source {
    type: string;
    key: string;
    label: string;
    filter: Record<string, string>;
}

export interface Form104Line {
    field: string;
    line_code: string;
    section: string;
    label: string;
    data_type: string;
    is_required: boolean;
    value_cents: number | null;
    amount_cents: number | null;
    base_cents: number | null;
    rate: string | null;
    applies_to: string;
    sources: Form104Source[];
    requires_signoff: boolean;
    unavailable: boolean;
    manual: boolean;
    note: string | null;
}

export interface Form104OfficialSection {
    key: string;
    title: string;
}

export interface Form104Draft {
    company: {
        public_id: string;
        legal_name: string;
        ruc: string;
    };
    period: {
        form_code: string;
        resolution: string;
        year: number;
        month: number;
        from: string;
        to: string;
        status: string | null;
        locked_at: string | null;
        computed_at: string | null;
        has_period: boolean;
        /** F5-07a: el último intento de agregación del período. */
        aggregation_status: 'pending' | 'completed' | 'failed';
        aggregation_error: string | null;
        aggregated_at: string | null;
    };
    status: {
        draft: boolean;
        editable: boolean;
        source: string;
        requires_signoff: boolean;
        notice: string;
    };
    lines: Form104Line[];
    proportionality: {
        sales_zero: number;
        sales_general: number;
        proportionality_factor: string | null;
        proportionality_complement: string | null;
        purchases_common_tax_to_credit: number | null;
    };
    previous_periods: { year: number; month: number; status: string | null; complete: boolean }[];
    findings: { code: string; label: string; severity: string; total: number }[];
    official_form: {
        form_code: string;
        resolution: string;
        sections: Form104OfficialSection[];
    };
}

export const useForm104Store = defineStore('form_104', () => {
    const draft = ref<Form104Draft | null>(null);
    const loading = ref(false);
    const recomputing = ref(false);
    const error = ref<string | null>(null);
    const year = ref(new Date().getFullYear());
    const month = ref(new Date().getMonth() + 1);

    const lines = computed(() => draft.value?.lines ?? []);
    const sections = computed(() => draft.value?.official_form.sections ?? []);
    const hasPeriod = computed(() => draft.value?.period.has_period ?? false);
    const blockingFindings = computed(
        () => draft.value?.findings.reduce((total, finding) => total + finding.total, 0) ?? 0,
    );

    /** Campos con valor calculado (no manuales ni sin fuente). */
    const computedFields = computed(
        () => lines.value.filter((line) => !line.unavailable && !line.manual).length,
    );

    const pendingFields = computed(
        () => lines.value.filter((line) => line.unavailable || line.manual).length,
    );

    async function fetchDraft(companyId: string): Promise<void> {
        loading.value = true;
        error.value = null;

        try {
            const response = await getJson<{ data: Form104Draft }>(
                `/api/v1/companies/${companyId}/form-104?year=${year.value}&month=${month.value}`,
            );

            draft.value = response.data;
        } catch (caught: unknown) {
            draft.value = null;
            error.value = caught instanceof Error ? caught.message : 'No se pudo cargar el borrador del 104.';
        } finally {
            loading.value = false;
        }
    }

    /**
     * F5-07d: recalcula los agregados del período desde la UI.
     *
     * El recálculo ya corre solo al cerrar cada sincronización del SRI; este
     * botón existe para un período que quedó vacío por un fallo anterior o con
     * datos corregidos a mano. Tras recalcular, vuelve a leer el borrador.
     */
    async function recompute(companyId: string): Promise<boolean> {
        recomputing.value = true;
        error.value = null;

        try {
            await postJson<unknown>(`/api/v1/companies/${companyId}/period-aggregates/recompute`, {
                year: year.value,
                month: month.value,
            });

            return true;
        } catch (caught: unknown) {
            error.value = caught instanceof Error ? caught.message : 'No se pudieron recalcular los agregados.';

            return false;
        } finally {
            recomputing.value = false;
        }
    }

    function setPeriod(nextYear: number, nextMonth: number): void {
        year.value = nextYear;
        month.value = nextMonth;
    }

    function reset(): void {
        draft.value = null;
        error.value = null;
        loading.value = false;
        recomputing.value = false;
    }

    return {
        draft,
        loading,
        recomputing,
        error,
        year,
        month,
        lines,
        sections,
        hasPeriod,
        blockingFindings,
        computedFields,
        pendingFields,
        fetchDraft,
        recompute,
        setPeriod,
        reset,
    };
});
