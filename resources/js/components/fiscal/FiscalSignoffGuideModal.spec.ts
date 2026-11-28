import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import FiscalSignoffGuideModal from '@/components/fiscal/FiscalSignoffGuideModal.vue';
import type { FiscalSignoffStage } from '@/stores/fiscalSignoffs';

const stage: FiscalSignoffStage = {
    key: 'ats-iva-nature-mapping',
    version: 1,
    title: 'Naturaleza IVA por código ATS',
    summary: 'Validar la relación entre códigos y naturaleza.',
    references: ['Ficha Técnica ATS'],
    fingerprint: 'fingerprint-1',
    status: 'pending',
    latest_review: null,
    history: [],
};

function mountGuide(selectedStage: FiscalSignoffStage | null) {
    return mount(FiscalSignoffGuideModal, {
        props: {
            open: true,
            stage: selectedStage,
        },
        global: {
            stubs: {
                Modal: {
                    props: ['title', 'description'],
                    template: `
                      <div role="dialog">
                        <h2>{{ title }}</h2>
                        <p>{{ description }}</p>
                        <slot name="body" />
                        <slot name="footer" />
                      </div>
                    `,
                },
                Button: {
                    props: ['label'],
                    template: '<button>{{ label }}</button>',
                },
            },
        },
    });
}

describe('FiscalSignoffGuideModal', () => {
    it('explica el recorrido sin exponer referencias de programación', () => {
        const wrapper = mountGuide(null);

        expect(wrapper.text()).toContain('Guía para la revisión contable');
        expect(wrapper.text()).toContain('abre Validación contable');
        expect(wrapper.text()).toContain('Los archivos de código relevantes se pueden abrir desde el visor controlado');
        expect(wrapper.text()).toContain('Formulario 104');
        expect(wrapper.text()).not.toContain('FiscalCatalogData.php');
    });

    it('explica los límites actuales de la clasificación por código ATS', () => {
        const wrapper = mountGuide(stage);

        expect(wrapper.text()).toContain('Qué debes comprobar');
        expect(wrapper.text()).toContain('no conserva ese detalle');
        expect(wrapper.text()).toContain('No es posible aprobar una clasificación automática completa de compras todavía.');
        expect(wrapper.text()).toContain('Ficha Técnica Transaccional Simplificado (ATS) del SRI');
    });
});
