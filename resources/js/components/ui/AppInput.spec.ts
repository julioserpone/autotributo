import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import AppInput from '@/components/ui/AppInput.vue';

describe('AppInput', () => {
    it('asocia la etiqueta, la ayuda y emite el valor', async () => {
        const wrapper = mount(AppInput, {
            props: {
                modelValue: '',
                label: 'Correo',
                hint: 'Usa tu correo del estudio',
                required: true,
            },
        });

        const input = wrapper.get('input');
        const label = wrapper.get('label');

        expect(label.text()).toContain('Correo');
        expect(label.attributes('for')).toBe(input.attributes('id'));
        expect(input.attributes('required')).toBeDefined();
        expect(input.attributes('aria-describedby')).toContain('-hint');

        await input.setValue('contador@estudio.ec');

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['contador@estudio.ec']);
    });

    it('expone el error con aria-invalid y role alert', () => {
        const wrapper = mount(AppInput, {
            props: {
                modelValue: '',
                label: 'RUC',
                error: 'El RUC es obligatorio.',
            },
        });

        const input = wrapper.get('input');

        expect(input.attributes('aria-invalid')).toBe('true');
        expect(input.attributes('aria-describedby')).toContain('-error');
        expect(wrapper.get('[role="alert"]').text()).toBe('El RUC es obligatorio.');
    });

    it('reenvía atributos al input, no al contenedor', () => {
        const wrapper = mount(AppInput, {
            props: { modelValue: '', label: 'Buscar' },
            attrs: { name: 'search', placeholder: 'Texto' },
        });

        expect(wrapper.get('input').attributes('name')).toBe('search');
        expect(wrapper.get('input').attributes('placeholder')).toBe('Texto');
    });

    it('respeta el id explícito', () => {
        const wrapper = mount(AppInput, {
            props: { modelValue: '', label: 'Empresa', id: 'empresa' },
        });

        expect(wrapper.get('label').attributes('for')).toBe('empresa');
        expect(wrapper.get('input').attributes('id')).toBe('empresa');
    });
});
