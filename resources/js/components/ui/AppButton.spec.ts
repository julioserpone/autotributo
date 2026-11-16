import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import AppButton from '@/components/ui/AppButton.vue';

describe('AppButton', () => {
    it('renderiza el contenido y emite el click', async () => {
        const wrapper = mount(AppButton, {
            slots: { default: 'Guardar' },
        });

        expect(wrapper.text()).toContain('Guardar');
        expect(wrapper.attributes('type')).toBe('button');

        await wrapper.trigger('click');

        expect(wrapper.emitted('click')).toHaveLength(1);
    });

    it('no emite click cuando está deshabilitado', async () => {
        const wrapper = mount(AppButton, {
            props: { disabled: true },
            slots: { default: 'Guardar' },
        });

        await wrapper.trigger('click');

        expect(wrapper.attributes('disabled')).toBeDefined();
        expect(wrapper.emitted('click')).toBeUndefined();
    });

    it('marca aria-busy y bloquea el click mientras carga', async () => {
        const wrapper = mount(AppButton, {
            props: { loading: true },
            slots: { default: 'Guardando' },
        });

        await wrapper.trigger('click');

        expect(wrapper.attributes('aria-busy')).toBe('true');
        expect(wrapper.attributes('disabled')).toBeDefined();
        expect(wrapper.emitted('click')).toBeUndefined();
    });

    it('aplica la variante y el tamaño solicitados', () => {
        const wrapper = mount(AppButton, {
            props: { variant: 'danger', size: 'lg' },
            slots: { default: 'Eliminar' },
        });

        expect(wrapper.classes()).toContain('bg-danger');
        expect(wrapper.classes()).toContain('h-11');
    });
});
