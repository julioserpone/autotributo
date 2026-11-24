/**
 * Etiquetas y tonos de los hallazgos fiscales (F3-06) para la bandeja.
 *
 * Los códigos `OUT_OF_PERIOD`, `MISSING_RETENTION` y `EXPENSE_CAP_EXCEEDED`
 * están reservados en el backend: se traducen para que, cuando se activen, la
 * UI no muestre el código crudo.
 */

export const findingCodeLabels: Record<string, string> = {
    TOTALS_MISMATCH: 'Totales inconsistentes',
    INVALID_RUC: 'RUC del proveedor inválido',
    GHOST_COMPANY: 'Empresa fantasma',
    DUPLICATE_SEQUENTIAL: 'Secuencial duplicado',
    OUT_OF_PERIOD: 'Comprobante fuera de período',
    MISSING_RETENTION: 'Retención faltante',
    EXPENSE_CAP_EXCEEDED: 'Tope de gasto excedido',
};

export const findingCodeHints: Record<string, string> = {
    TOTALS_MISMATCH: 'El total del comprobante no coincide con la suma de sus componentes.',
    INVALID_RUC: 'El RUC del emisor no supera la validación del SRI.',
    GHOST_COMPANY: 'El emisor figura en la lista de empresas fantasma del SRI.',
    DUPLICATE_SEQUENTIAL: 'Hay dos comprobantes con el mismo establecimiento y secuencial.',
};

export function findingCodeLabel(code: string): string {
    return findingCodeLabels[code] ?? code;
}

export function findingCodeHint(code: string): string | null {
    return findingCodeHints[code] ?? null;
}

/**
 * Desglose del cuadre de totales de un hallazgo `TOTALS_MISMATCH`.
 *
 * El backend guarda el total esperado y la diferencia en `metadata`; si el
 * hallazgo es de una detección anterior a ese cambio, se devuelve `null` y la
 * vista no muestra el bloque.
 */
export interface TotalsBreakdown {
    subtotal_cents: number;
    discount_cents: number;
    tax_cents: number;
    ice_cents: number;
    irbpnr_cents: number;
    tip_cents: number;
    total_cents: number;
    expected_total_cents: number;
    difference_cents: number;
}

function cents(metadata: Record<string, unknown>, key: string): number {
    const value = metadata[key];

    return typeof value === 'number' ? value : 0;
}

export function totalsBreakdown(metadata: Record<string, unknown> | null): TotalsBreakdown | null {
    if (metadata === null || typeof metadata.expected_total_cents !== 'number') return null;

    return {
        subtotal_cents: cents(metadata, 'subtotal_cents'),
        discount_cents: cents(metadata, 'discount_cents'),
        tax_cents: cents(metadata, 'tax_cents'),
        ice_cents: cents(metadata, 'ice_cents'),
        irbpnr_cents: cents(metadata, 'irbpnr_cents'),
        tip_cents: cents(metadata, 'tip_cents'),
        total_cents: cents(metadata, 'total_cents'),
        expected_total_cents: cents(metadata, 'expected_total_cents'),
        difference_cents: cents(metadata, 'difference_cents'),
    };
}

/** RUC del emisor sin separadores, para comparar con el formato oficial. */
export function normalizedRuc(metadata: Record<string, unknown> | null): string | null {
    const value = metadata?.issuer_ruc_digits;

    return typeof value === 'string' && value !== '' ? value : null;
}

export type FindingSeverity = 'info' | 'warning' | 'critical';
export type FindingStatus = 'open' | 'acknowledged' | 'resolved' | 'dismissed';

export const severityLabels: Record<string, string> = {
    info: 'Informativo',
    warning: 'Advertencia',
    critical: 'Crítico',
};

export const statusLabels: Record<string, string> = {
    open: 'Abierto',
    acknowledged: 'Reconocido',
    resolved: 'Resuelto',
    dismissed: 'Descartado',
};

export function severityLabel(severity: string): string {
    return severityLabels[severity] ?? severity;
}

export function severityColor(severity: string): 'info' | 'warning' | 'error' {
    if (severity === 'critical') return 'error';
    if (severity === 'warning') return 'warning';
    return 'info';
}

export function statusLabel(status: string): string {
    return statusLabels[status] ?? status;
}

export function statusColor(status: string): 'neutral' | 'info' | 'success' | 'error' {
    switch (status) {
        case 'open':
            return 'error';
        case 'acknowledged':
            return 'info';
        case 'resolved':
            return 'success';
        case 'dismissed':
            return 'neutral';
        default:
            return 'neutral';
    }
}

/** Acción que puede ejecutarse sobre un hallazgo desde la bandeja. */
export type FindingAction = 'acknowledged' | 'resolved' | 'dismissed';

export const actionLabels: Record<FindingAction, string> = {
    acknowledged: 'Reconocer',
    resolved: 'Resolver',
    dismissed: 'Descartar',
};

export function formatMoney(cents: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(cents / 100);
}

export function formatDate(value: string | null): string {
    if (!value) return '—';

    return new Date(value).toLocaleDateString('es-EC', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}
