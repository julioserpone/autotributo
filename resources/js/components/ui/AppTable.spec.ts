import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import AppTable from '@/components/ui/AppTable.vue';

const columns = [
    { key: 'issuer', label: 'Proveedor' },
    { key: 'total', label: 'Total', align: 'right' as const },
];

const rows = [
    { id: 'inv-1', issuer: 'Ferretería Central', total: '$120,00' },
    { id: 'inv-2', issuer: 'Papelería Norte', total: '$18,50' },
];

describe('AppTable', () => {
    it('renderiza cabeceras y filas', () => {
        const wrapper = mount(AppTable, {
            props: { columns, rows, caption: 'Comprobantes' },
        });

        const headers = wrapper.findAll('th');

        expect(headers).toHaveLength(2);
        expect(headers[0]?.text()).toBe('Proveedor');
        expect(headers[1]?.classes()).toContain('text-right');
        expect(wrapper.findAll('tbody tr')).toHaveLength(2);
        expect(wrapper.text()).toContain('Ferretería Central');
        expect(wrapper.get('caption').text()).toBe('Comprobantes');
    });

    it('permite personalizar una celda con un slot', () => {
        const wrapper = mount(AppTable, {
            props: { columns, rows },
            slots: {
                'cell-total': (params: { value: unknown }) => `Total: ${String(params.value)}`,
            },
        });

        expect(wrapper.text()).toContain('Total: $120,00');
    });

    it('muestra el estado vacío', () => {
        const wrapper = mount(AppTable, {
            props: { columns, rows: [], emptyText: 'No hay comprobantes.' },
        });

        expect(wrapper.text()).toContain('No hay comprobantes.');
    });

    it('muestra el error con role alert', () => {
        const wrapper = mount(AppTable, {
            props: { columns, rows: [], error: 'No se pudo cargar la bandeja.' },
        });

        expect(wrapper.get('[role="alert"]').text()).toBe('No se pudo cargar la bandeja.');
    });

    it('anuncia la carga y dibuja esqueletos', () => {
        const wrapper = mount(AppTable, {
            props: { columns, rows: [], loading: true, skeletonRows: 3 },
        });

        expect(wrapper.attributes('aria-busy')).toBe('true');
        expect(wrapper.findAll('tbody tr')).toHaveLength(3);
        expect(wrapper.findAll('.animate-pulse')).toHaveLength(6);
    });

    it('emite la selección de filas y de todas', async () => {
        const wrapper = mount(AppTable, {
            props: { columns, rows, selectable: true, selected: [] },
        });

        const checkboxes = wrapper.findAll('tbody input[type="checkbox"]');

        await checkboxes[0]?.trigger('change');

        expect(wrapper.emitted('update:selected')?.[0]).toEqual([['inv-1']]);

        const selectAll = wrapper.get('thead input[type="checkbox"]');

        await selectAll.trigger('change');

        expect(wrapper.emitted('update:selected')?.[1]).toEqual([['inv-1', 'inv-2']]);
    });

    it('marca la cabecera como indeterminada con selección parcial', () => {
        const wrapper = mount(AppTable, {
            props: { columns, rows, selectable: true, selected: ['inv-1'] },
        });

        const selectAll = wrapper.get('thead input[type="checkbox"]').element as HTMLInputElement;

        expect(selectAll.indeterminate).toBe(true);
    });
});
