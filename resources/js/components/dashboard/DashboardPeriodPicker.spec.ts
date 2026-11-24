import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import DashboardPeriodPicker from '@/components/dashboard/DashboardPeriodPicker.vue';

describe('DashboardPeriodPicker', () => {
    it('muestra los últimos doce períodos con el actual seleccionado', () => {
        const wrapper = mount(DashboardPeriodPicker, {
            props: { year: 2026, month: 11 },
        });

        const select = wrapper.find('select');

        expect(wrapper.findAll('option')).toHaveLength(12);
        expect(wrapper.findAll('option')[0]?.text()).toBe('Noviembre 2026');
        expect(wrapper.findAll('option')[11]?.text()).toBe('Diciembre 2025');
        expect((select.element as HTMLSelectElement).value).toBe('2026-11');
        expect(select.attributes('aria-label')).toBe('Período fiscal, Noviembre 2026');
    });

    it('emite el período anterior y el siguiente cruzando el año', async () => {
        const wrapper = mount(DashboardPeriodPicker, {
            props: { year: 2026, month: 1 },
        });

        await wrapper.find('button[aria-label="Mes anterior"]').trigger('click');
        expect(wrapper.emitted('change-period')?.[0]).toEqual([{ year: 2025, month: 12 }]);

        await wrapper.find('button[aria-label="Mes siguiente"]').trigger('click');
        expect(wrapper.emitted('change-period')?.[1]).toEqual([{ year: 2026, month: 2 }]);
    });

    it('emite el período elegido en el desplegable', async () => {
        const wrapper = mount(DashboardPeriodPicker, {
            props: { year: 2026, month: 11 },
        });

        await wrapper.find('select').setValue('2026-9');

        expect(wrapper.emitted('change-period')?.[0]).toEqual([{ year: 2026, month: 9 }]);
    });
});
