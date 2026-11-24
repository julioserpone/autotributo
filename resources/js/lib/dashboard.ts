/**
 * Catálogo de etiquetas del panel tributario (F4-08).
 *
 * Los tipos viven en `@/stores/dashboard`; aquí solo están las traducciones que
 * necesita la vista para no mostrar códigos crudos del backend.
 */

export const MONTH_LABELS: readonly string[] = [
    'Enero',
    'Febrero',
    'Marzo',
    'Abril',
    'Mayo',
    'Junio',
    'Julio',
    'Agosto',
    'Septiembre',
    'Octubre',
    'Noviembre',
    'Diciembre',
];

/** Abreviaturas para ejes de gráficos. */
export const MONTH_SHORT_LABELS: readonly string[] = [
    'Ene',
    'Feb',
    'Mar',
    'Abr',
    'May',
    'Jun',
    'Jul',
    'Ago',
    'Sep',
    'Oct',
    'Nov',
    'Dic',
];

export function monthLabel(month: number): string {
    return MONTH_LABELS[month - 1] ?? String(month);
}

export function periodLabel(year: number, month: number): string {
    return `${monthLabel(month)} ${year}`;
}

export const periodStatusLabels: Record<string, string> = {
    open: 'Abierto',
    closed: 'Cerrado',
    locked: 'Bloqueado',
};

export function periodStatusLabel(status: string | null): string {
    if (status === null) return 'Sin calcular';

    return periodStatusLabels[status] ?? status;
}

export function periodStatusColor(status: string | null): 'neutral' | 'success' | 'warning' {
    switch (status) {
        case 'closed':
        case 'locked':
            return 'success';
        case 'open':
            return 'warning';
        default:
            return 'neutral';
    }
}

export const severityLabels: Record<string, string> = {
    info: 'Informativo',
    warning: 'Advertencias',
    critical: 'Críticos',
};

export function severityLabel(severity: string): string {
    return severityLabels[severity] ?? severity;
}

function numberFormatter(): Intl.NumberFormat {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

let cachedMoneyFormatter: Intl.NumberFormat | null = null;

export function formatCents(cents: number): string {
    cachedMoneyFormatter ??= numberFormatter();

    return cachedMoneyFormatter.format(cents / 100);
}

/** Importe compacto para ejes (`$1,2k`). */
export function formatCompactCents(cents: number): string {
    return new Intl.NumberFormat('es-EC', {
        style: 'currency',
        currency: 'USD',
        notation: 'compact',
        maximumFractionDigits: 1,
    }).format(cents / 100);
}

export function formatCount(value: number, singular: string, plural: string): string {
    return `${value} ${value === 1 ? singular : plural}`;
}
