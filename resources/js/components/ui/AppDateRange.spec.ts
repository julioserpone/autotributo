import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import AppDateRange from '@/components/ui/AppDateRange.vue';

describe('AppDateRange', () => {
    it('renderiza dos fechas con etiquetas accesibles', () => {
        const wrapper = mount(AppDateRange, {
            props: {
                modelValue: { from: null, to: null },
                label: 'Período',
            },
        });

        const dates = wrapper.findAll('input[type="date"]');
        const from = dates[0];
        const to = dates[1];

        expect(dates).toHaveLength(2);
        expect(from).toBeDefined();
        expect(to).toBeDefined();
        expect(wrapper.text()).toContain('Desde');
        expect(wrapper.text()).toContain('Hasta');
        expect(wrapper.get('label').attributes('for')).toBe(from?.attributes('id'));
    });

    it('emite el rango al cambiar una fecha', async () => {
        const wrapper = mount(AppDateRange, {
            props: {
                modelValue: { from: null, to: null },
                label: 'Período',
            },
        });

        await wrapper.get('input[type="date"]').setValue('2026-01-01');

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([
            { from: '2026-01-01', to: null },
        ]);
    });

    it('avisa cuando la fecha inicial supera la final', () => {
        const wrapper = mount(AppDateRange, {
            props: {
                modelValue: { from: '2026-02-01', to: '2026-01-01' },
                label: 'Período',
            },
        });

        expect(wrapper.get('[role="alert"]').text()).toContain('no puede ser posterior');
        expect(wrapper.get('input[type="date"]').attributes('aria-invalid')).toBe('true');
    });

    it('permite limpiar el rango', async () => {
        const wrapper = mount(AppDateRange, {
            props: {
                modelValue: { from: '2026-01-01', to: '2026-01-31' },
                label: 'Período',
            },
        });

        const clear = wrapper.findAll('button').find((button) => button.text() === 'Limpiar');

        expect(clear).toBeDefined();

        await clear?.trigger('click');

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([{ from: null, to: null }]);
    });
});
