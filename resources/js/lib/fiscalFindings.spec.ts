import { describe, expect, it } from 'vitest';

import {
    actionLabels,
    findingCodeHint,
    findingCodeLabel,
    formatDate,
    formatMoney,
    severityColor,
    severityLabel,
    statusColor,
    statusLabel,
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
});
