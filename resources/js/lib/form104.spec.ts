import { describe, expect, it } from 'vitest';

import type { Form104Line } from '@/stores/form104';

import {
    form104DrilldownRoute,
    form104Kpis,
    form104LineState,
    form104Value,
    formatFactor,
    formatPercent,
    groupForm104Lines,
} from '@/lib/form104';

function line(overrides: Partial<Form104Line> = {}): Form104Line {
    return {
        field: '401',
        line_code: '401',
        section: 'ventas',
        label: 'Ventas locales gravadas',
        data_type: 'amount',
        is_required: true,
        value_cents: 100_000,
        amount_cents: 100_000,
        base_cents: null,
        rate: null,
        applies_to: 'ventas',
        sources: [],
        requires_signoff: false,
        unavailable: false,
        manual: false,
        note: null,
        ...overrides,
    };
}

describe('form104LineState', () => {
    it('prioriza manual y pendiente sobre cualquier cifra', () => {
        expect(form104LineState(line({ manual: true, value_cents: 5 }))).toBe('manual');
        expect(form104LineState(line({ unavailable: true, value_cents: 5 }))).toBe('unavailable');
    });

    it('distingue calculado, firma y sin movimiento', () => {
        expect(form104LineState(line())).toBe('value');
        expect(form104LineState(line({ requires_signoff: true }))).toBe('signoff');
        expect(form104LineState(line({ value_cents: 0 }))).toBe('zero');
    });

    it('un casillero de tarifa con valor siempre es calculado', () => {
        expect(form104LineState(line({ data_type: 'rate', rate: '0.500000', value_cents: null })))
            .toBe('value');
    });
});

describe('form104Value', () => {
    const money = (cents: number): string => `$${(cents / 100).toFixed(2)}`;

    it('formatea importes y ratios', () => {
        expect(form104Value(line(), money)).toBe('$1000.00');
        expect(form104Value(line({ data_type: 'rate', rate: '0.5' }), money)).toBe('50,00 %');
    });

    it('muestra guion cuando no hay cifra', () => {
        expect(form104Value(line({ value_cents: null }), money)).toBe('—');
        expect(form104Value(line({ data_type: 'rate', rate: null }), money)).toBe('—');
    });
});

describe('groupForm104Lines', () => {
    it('respeta el orden y los títulos del formulario oficial', () => {
        const groups = groupForm104Lines(
            [
                line({ field: '999', section: 'pago', value_cents: null, unavailable: true }),
                line(),
            ],
            [
                { key: 'ventas', title: 'Ventas' },
                { key: 'pago', title: 'Pago' },
            ],
        );

        expect(groups.map((group) => group.key)).toEqual(['ventas', 'pago']);
        expect(groups[0]?.computed).toBe(1);
        expect(groups[1]?.pending).toBe(1);
        expect(groups[1]?.lines[0]?.field).toBe('999');
    });
});

describe('form104Kpis', () => {
    it('toma las cifras de los campos del resumen', () => {
        const draft = {
            lines: [
                line({ field: '401', value_cents: 100_000 }),
                line({ field: '429', value_cents: 15_000 }),
                line({ field: '529', value_cents: 6_000 }),
                line({ field: '564', value_cents: 5_000 }),
                line({ field: '799', value_cents: 1_000 }),
                line({ field: '890', value_cents: null, unavailable: true }),
            ],
            official_form: { sections: [] },
        } as unknown as Parameters<typeof form104Kpis>[0];

        expect(form104Kpis(draft)).toEqual({
            gravadasBaseCents: 100_000,
            ivaVentasCents: 15_000,
            ivaComprasCents: 6_000,
            creditoCents: 5_000,
            retencionesIvaCents: 1_000,
            computedFields: 5,
            pendingFields: 1,
        });
    });
});

describe('form104DrilldownRoute', () => {
    it('propaga fechas y empresa al filtro de la bandeja', () => {
        const route = form104DrilldownRoute(
            line({
                sources: [
                    {
                        type: 'fiscal_documents',
                        key: 'ventas',
                        label: 'Ver comprobantes',
                        filter: {
                            date_from: '2026-09-01',
                            date_to: '2026-09-30',
                            direction: 'ventas',
                        },
                    },
                ],
            }),
        );

        expect(route).toEqual({
            name: 'fiscal_documents',
            query: { date_from: '2026-09-01', date_to: '2026-09-30' },
        });
    });

    it('devuelve null si el casillero no tiene fuente', () => {
        expect(form104DrilldownRoute(line())).toBeNull();
    });
});

describe('formatPercent', () => {
    it('convierte ratios a porcentajes y tolera valores no numéricos', () => {
        expect(formatPercent('0.166667')).toBe('16,67 %');
        expect(formatFactor(null)).toBe('—');
        expect(formatPercent('abc')).toBe('abc');
    });
});
