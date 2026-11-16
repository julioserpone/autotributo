import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import AppSelect from '@/components/ui/AppSelect.vue';

const options = [
    { value: 1, label: 'Alimentación' },
    { value: 2, label: 'Vivienda' },
    { value: 3, label: 'No deducible', disabled: true },
];

describe('AppSelect', () => {
    it('renderiza las opciones y el placeholder', () => {
        const wrapper = mount(AppSelect, {
            props: {
                modelValue: null,
                options,
                label: 'Categoría',
                placeholder: 'Selecciona una categoría',
            },
        });

        const rendered = wrapper.findAll('option');

        expect(rendered).toHaveLength(4);
        expect(rendered[0]?.text()).toBe('Selecciona una categoría');
        expect(rendered[3]?.attributes('disabled')).toBeDefined();
    });

    it('emite el valor original, no el string del DOM', async () => {
        const wrapper = mount(AppSelect, {
            props: { modelValue: 1, options },
        });

        await wrapper.get('select').setValue('2');

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([2]);
    });

    it('emite null al elegir el placeholder', async () => {
        const wrapper = mount(AppSelect, {
            props: { modelValue: 1, options, placeholder: 'Todas' },
        });

        await wrapper.get('select').setValue('');

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([null]);
    });

    it('muestra el error y lo asocia al select', () => {
        const wrapper = mount(AppSelect, {
            props: { modelValue: null, options, label: 'Categoría', error: 'Elige una categoría.' },
        });

        expect(wrapper.get('select').attributes('aria-invalid')).toBe('true');
        expect(wrapper.get('[role="alert"]').text()).toBe('Elige una categoría.');
    });
});
