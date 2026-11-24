import { describe, expect, it } from 'vitest';

import {
    actionLabels,
    findingCodeHint,
    findingCodeLabel,
    formatDate,
    formatMoney,
    normalizedRuc,
    severityColor,
    severityLabel,
    statusColor,
    statusLabel,
    totalsBreakdown,
} from '@/lib/fiscalFindings';

describe('lib/fiscalFindings', () => {
    it('traduce los códigos activos y reservados', () => {
        expect(findingCodeLabel('TOTALS_MISMATCH')).toBe('Totales inconsistentes');
        expect(findingCodeLabel('GHOST_COMPANY')).toBe('Empresa fantasma');
        expect(findingCodeLabel('EXPENSE_CAP_EXCEEDED')).toBe('Tope de gasto excedido');
        expect(findingCodeLabel('DESCONOCIDO')).toBe('DESCONOCIDO');
    });

    it('describe solo los códigos con ayuda contextual', () => {
        expect(findingCodeHint('INVALID_RUC')).toContain('RUC');
        expect(findingCodeHint('OUT_OF_PERIOD')).toBeNull();
    });

    it('asigna tonos por severidad y estado', () => {
        expect(severityColor('critical')).toBe('error');
        expect(severityColor('warning')).toBe('warning');
        expect(severityColor('info')).toBe('info');
        expect(statusColor('open')).toBe('error');
        expect(statusColor('acknowledged')).toBe('info');
        expect(statusColor('resolved')).toBe('success');
        expect(statusColor('dismissed')).toBe('neutral');
    });

    it('traduce severidades, estados y acciones', () => {
        expect(severityLabel('critical')).toBe('Crítico');
        expect(statusLabel('acknowledged')).toBe('Reconocido');
        expect(actionLabels.dismissed).toBe('Descartar');
        expect(severityLabel('otro')).toBe('otro');
    });

    it('formatea importes en centavos y fechas', () => {
        expect(formatMoney(11500)).toBe('$115.00');
        expect(formatDate(null)).toBe('—');
        expect(formatDate('2026-11-16T00:00:00+00:00')).toContain('2026');
    });

    it('extrae el desglose del cuadre cuando existe', () => {
        const breakdown = totalsBreakdown({
            subtotal_cents: 262,
            discount_cents: 89,
            tax_cents: 0,
            total_cents: 262,
            expected_total_cents: 173,
            difference_cents: 89,
        });

        expect(breakdown).not.toBeNull();
        expect(breakdown?.difference_cents).toBe(89);
        expect(breakdown?.discount_cents).toBe(89);
        expect(breakdown?.ice_cents).toBe(0);
        expect(breakdown?.tip_cents).toBe(0);
    });

    it('no inventa desglose para hallazgos anteriores al cambio', () => {
        expect(totalsBreakdown(null)).toBeNull();
        expect(totalsBreakdown({ access_key: '123' })).toBeNull();
    });

    it('normaliza el RUC del emisor desde los metadatos', () => {
        expect(normalizedRuc({ issuer_ruc_digits: '0990858322001' })).toBe('0990858322001');
        expect(normalizedRuc({})).toBeNull();
        expect(normalizedRuc(null)).toBeNull();
    });
});
