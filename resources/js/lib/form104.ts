import type { Form104Draft, Form104Line, Form104OfficialSection } from '@/stores/form104';

/**
 * Etiquetas y agrupaciones del borrador del Formulario 104 (F4-09).
 *
 * La estructura de secciones viene del formulario oficial (`official_form`):
 * no se inventa un agrupamiento propio, para que la vista coincida con el XLS
 * que se presentará al SRI.
 */

export type Form104LineState = 'value' | 'zero' | 'signoff' | 'manual' | 'unavailable';

/**
 * Estado visual de un casillero.
 *
 * `manual` y `unavailable` ganan sobre el resto: si no hay cifra derivada de
 * los comprobantes, la vista no debe mostrar un cero como si fuera un cálculo.
 */
export function form104LineState(line: Form104Line): Form104LineState {
    if (line.manual) return 'manual';
    if (line.unavailable) return 'unavailable';
    if (line.data_type === 'rate') return 'value';
    if (line.requires_signoff) return 'signoff';
    if ((line.value_cents ?? 0) === 0) return 'zero';

    return 'value';
}

export const form104StateLabels: Record<Form104LineState, string> = {
    value: 'Calculado',
    zero: 'Sin movimiento',
    signoff: 'Requiere firma',
    manual: 'Entrada manual',
    unavailable: 'Pendiente',
};

const form104StateColors: Record<Form104LineState, 'success' | 'neutral' | 'warning'> = {
    value: 'success',
    zero: 'neutral',
    signoff: 'warning',
    manual: 'neutral',
    unavailable: 'warning',
};

export function form104StateLabel(line: Form104Line): string {
    return form104StateLabels[form104LineState(line)];
}

export function form104StateColor(line: Form104Line): 'success' | 'neutral' | 'warning' {
    return form104StateColors[form104LineState(line)];
}

/** Una sección del formulario con sus campos, en el orden oficial. */
export interface Form104SectionGroup {
    key: string;
    title: string;
    lines: Form104Line[];
    computed: number;
    pending: number;
}

export function groupForm104Lines(
    lines: Form104Line[],
    sections: Form104OfficialSection[],
): Form104SectionGroup[] {
    return sections.map((section) => {
        const sectionLines = lines.filter((line) => line.section === section.key);

        return {
            key: section.key,
            title: section.title,
            lines: sectionLines,
            computed: sectionLines.filter((line) => !line.unavailable && !line.manual).length,
            pending: sectionLines.filter((line) => line.unavailable || line.manual).length,
        };
    });
}

/** Valor legible de un casillero, con `—` cuando no hay cifra. */
export function form104Value(line: Form104Line, formatCents: (cents: number) => string): string {
    if (line.data_type === 'rate') {
        return line.rate === null ? '—' : formatPercent(line.rate);
    }

    if (line.value_cents === null) {
        return '—';
    }

    return formatCents(line.value_cents);
}

/** Ratio (`0.500000`) → porcentaje con dos decimales (`50,00 %`). */
export function formatPercent(rate: string): string {
    const numeric = Number(rate);

    if (Number.isNaN(numeric)) return rate;

    return `${new Intl.NumberFormat('es-EC', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(numeric * 100)} %`;
}

export function formatFactor(factor: string | null): string {
    return factor === null ? '—' : formatPercent(factor);
}

/** Etiqueta corta para los KPIs de cabecera del borrador. */
export interface Form104Kpis {
    gravadasBaseCents: number;
    ivaVentasCents: number;
    ivaComprasCents: number;
    creditoCents: number;
    retencionesIvaCents: number;
    computedFields: number;
    pendingFields: number;
}

export function form104Kpis(draft: Form104Draft): Form104Kpis {
    const byField = (field: string): number =>
        draft.lines.find((line) => line.field === field)?.value_cents ?? 0;

    return {
        gravadasBaseCents: byField('401'),
        ivaVentasCents: byField('429'),
        ivaComprasCents: byField('529'),
        creditoCents: byField('564'),
        retencionesIvaCents: byField('799'),
        computedFields: draft.lines.filter((line) => !line.unavailable && !line.manual).length,
        pendingFields: draft.lines.filter((line) => line.unavailable || line.manual).length,
    };
}

/**
 * Enlace a la bandeja con los filtros del drill-down de un casillero.
 *
 * La bandeja entiende `company_id` y las fechas; `direction` todavía no es un
 * filtro de la UI, así que no se propaga.
 */
export function form104DrilldownRoute(
    line: Form104Line,
): { name: 'fiscal_documents'; query: Record<string, string> } | null {
    const source = line.sources[0];

    if (source === undefined) return null;

    const query: Record<string, string> = {};

    for (const key of ['date_from', 'date_to', 'company_id'] as const) {
        const value = source.filter[key];

        if (value !== undefined && value !== '') {
            query[key] = value;
        }
    }

    return Object.keys(query).length > 0 ? { name: 'fiscal_documents', query } : null;
}
