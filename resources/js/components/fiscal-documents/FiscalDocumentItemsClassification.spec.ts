import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { describe, expect, it } from 'vitest';

import FiscalDocumentItemsClassification from '@/components/fiscal-documents/FiscalDocumentItemsClassification.vue';
import type { FiscalDocumentItemApi } from '@/stores/fiscalDocuments';

function item(overrides: Partial<FiscalDocumentItemApi> = {}): FiscalDocumentItemApi {
    return {
        line_number: 1,
        description: 'Servicios de consultoría contable',
        main_code: 'SRV-001',
        auxiliary_code: null,
        quantity: '1.000000',
        unit_price: '100.00',
        discount: '0.00',
        subtotal_cents: 10_000,
        total_cents: 11_500,
        classification: {
            status: 'auto',
            expense_category_id: 3,
            expense_category: { id: 3, code: 'servicios', name: 'Servicios profesionales' },
            method: 'rule',
            confidence: 1,
            prompt_version: null,
        },
        ...overrides,
    };
}

async function mountComponent(items: FiscalDocumentItemApi[], loading = false) {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'dashboard', component: { template: '<div />' } },
            { path: '/classifications', name: 'classifications', component: { template: '<div />' } },
        ],
    });
    await router.push('/');
    await router.isReady();

    return mount(FiscalDocumentItemsClassification, {
        props: { items, loading },
        global: { plugins: [router] },
    });
}

describe('FiscalDocumentItemsClassification', () => {
    it('muestra la categoría y el estado de una línea clasificada por regla', async () => {
        const wrapper = await mountComponent([item()]);

        expect(wrapper.text()).toContain('Servicios de consultoría contable');
        expect(wrapper.text()).toContain('Automática');
        expect(wrapper.text()).toContain('Servicios profesionales');
        expect(wrapper.text()).toContain('Regla');
        expect(wrapper.text()).toContain('100 %');
        expect(wrapper.text()).toContain('$115.00');
    });

    it('marca como en revisión una sugerencia de IA con confianza baja y enlaza a la cola', async () => {
        const wrapper = await mountComponent([
            item({
                classification: {
                    status: 'review',
                    expense_category_id: 7,
                    expense_category: { id: 7, code: 'combustible', name: 'Combustible' },
                    method: 'llm',
                    confidence: 0.62,
                    prompt_version: 'v1',
                },
            }),
        ]);

        expect(wrapper.text()).toContain('En revisión');
        expect(wrapper.text()).toContain('IA');
        expect(wrapper.text()).toContain('62 %');
        expect(wrapper.text()).toContain('Revisar');
    });

    it('muestra las líneas sin clasificar sin categoría ni confianza', async () => {
        const wrapper = await mountComponent([
            item({
                classification: {
                    status: 'pending',
                    expense_category_id: null,
                    expense_category: null,
                    method: null,
                    confidence: null,
                    prompt_version: null,
                },
            }),
        ]);

        expect(wrapper.text()).toContain('Sin clasificar');
        expect(wrapper.text()).not.toContain('Regla');
        expect(wrapper.text()).not.toContain('%');
    });

    it('resume los estados de todas las líneas', async () => {
        const wrapper = await mountComponent([
            item(),
            item({ line_number: 2, classification: { ...item().classification, status: 'review' } }),
            item({ line_number: 3, classification: { ...item().classification, status: 'pending', expense_category: null, method: null, confidence: null } }),
        ]);

        expect(wrapper.text()).toContain('Automática: 1');
        expect(wrapper.text()).toContain('En revisión: 1');
        expect(wrapper.text()).toContain('Sin clasificar: 1');
    });

    it('avisa cuando el comprobante no trae líneas', async () => {
        const wrapper = await mountComponent([]);

        expect(wrapper.text()).toContain('no trae líneas de detalle');
    });

    it('muestra esqueletos mientras carga', async () => {
        const wrapper = await mountComponent([], true);

        expect(wrapper.findAll('.animate-pulse').length).toBeGreaterThan(0);
        expect(wrapper.text()).not.toContain('no trae líneas de detalle');
    });
});
