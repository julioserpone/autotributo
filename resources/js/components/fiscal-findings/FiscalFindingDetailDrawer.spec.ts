import { mount, type VueWrapper } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { afterEach, describe, expect, it } from 'vitest';

import FiscalFindingDetailDrawer from '@/components/fiscal-findings/FiscalFindingDetailDrawer.vue';
import type { FiscalFinding } from '@/stores/fiscalFindings';

/**
 * El `USlideover` real se monta por portal y tiene animación de entrada, así
 * que `wrapper.text()` no lo incluye de inmediato. Se monta en
 * `document.body` y se espera a que aparezca el contenido.
 */
let wrapper: VueWrapper | null = null;

function makeFinding(overrides: Partial<FiscalFinding> = {}): FiscalFinding {
    return {
        id: 27,
        code: 'TOTALS_MISMATCH',
        severity: 'critical',
        status: 'open',
        title: 'Totales inconsistentes',
        description: 'El total declarado del comprobante 1711202601… es $2.62, pero la suma de sus componentes da $2.62 (diferencia $0.00).',
        note: null,
        metadata: {
            subtotal_cents: 262,
            discount_cents: 89,
            tax_cents: 0,
            ice_cents: 0,
            irbpnr_cents: 0,
            tip_cents: 0,
            total_cents: 262,
            expected_total_cents: 262,
            difference_cents: 0,
        },
        created_at: '2026-11-24T10:00:00Z',
        resolved_at: null,
        resolved_by: null,
        company: { public_id: 'company-1', ruc: '1790012345001', legal_name: 'ACME C.A.' },
        source: {
            public_id: 'doc-1',
            access_key: '1711202601099085832200123180210004628500990858310',
            number: '001-001-000000462',
            issuer_ruc: '0990858322001',
            issuer_name: 'EMPRESA ELECTRICA QUITO S.A.',
            issue_date: '2026-10-09',
            subtotal_cents: 262,
            tax_cents: 0,
            total_cents: 262,
        },
        ...overrides,
    } as FiscalFinding;
}

async function mountDrawer(finding: FiscalFinding): Promise<VueWrapper> {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'dashboard', component: { template: '<div />' } },
            { path: '/fiscal-documents', name: 'fiscal_documents', component: { template: '<div />' } },
        ],
    });
    await router.push('/');
    await router.isReady();

    wrapper = mount(FiscalFindingDetailDrawer, {
        props: { open: true, finding, actionsEnabled: true },
        global: { plugins: [router] },
        attachTo: document.body,
    });

    for (let attempt = 0; attempt < 20 && document.body.textContent?.includes('Detalle del hallazgo') !== true; attempt += 1) {
        await new Promise((resolve) => setTimeout(resolve, 20));
    }

    return wrapper;
}

function bodyText(): string {
    return document.body.textContent ?? '';
}

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
});

describe('FiscalFindingDetailDrawer', () => {
    it('muestra el desglose del cuadre cuando el hallazgo trae importes', async () => {
        await mountDrawer(makeFinding());

        expect(bodyText()).toContain('Cuadre de totales');
        expect(bodyText()).toContain('Total que debería declarar el emisor');
        expect(bodyText()).toContain('Total declarado por el emisor');
        expect(bodyText()).toContain('Descuento global (ya incluido en la base)');
        expect(bodyText()).toContain('Cuadra');
    });

    it('marca la diferencia cuando el total no cuadra', async () => {
        await mountDrawer(makeFinding({
            metadata: {
                subtotal_cents: 262,
                discount_cents: 89,
                tax_cents: 0,
                total_cents: 262,
                expected_total_cents: 173,
                difference_cents: 89,
            },
        }));

        expect(bodyText()).toContain('Diferencia');
        expect(bodyText()).toContain('$0.89');
    });

    it('explica el RUC válido del emisor cuando lo publica con separadores', async () => {
        await mountDrawer(makeFinding({
            code: 'INVALID_RUC',
            metadata: { issuer_ruc_digits: '0990858322001' },
        }));

        expect(bodyText()).toContain('RUC válido según el SRI');
        expect(bodyText()).toContain('0990858322001');
        expect(bodyText()).not.toContain('Cuadre de totales');
    });

    it('no muestra el desglose en hallazgos sin importes', async () => {
        await mountDrawer(makeFinding({ code: 'GHOST_COMPANY', metadata: {} }));

        expect(bodyText()).toContain('Detalle del hallazgo');
        expect(bodyText()).not.toContain('Cuadre de totales');
    });
});
