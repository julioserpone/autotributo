import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
import { afterEach, describe, expect, it } from 'vitest';

import AppCombobox from '@/components/ui/AppCombobox.vue';

const options = [
    { value: 'alimentacion', label: 'Alimentación' },
    { value: 'vivienda', label: 'Vivienda' },
    { value: 'operativo', label: 'Gasto operativo', description: 'Insumos y servicios' },
];

const wrappers: VueWrapper[] = [];

function mountCombobox(modelValue: string | null = null, clearable = false) {
    const wrapper = mount(AppCombobox, {
        attachTo: document.body,
        props: {
            modelValue,
            options,
            label: 'Categoría',
            clearable,
        },
    });

    wrappers.push(wrapper);

    return wrapper;
}

afterEach(() => {
    for (const wrapper of wrappers) {
        wrapper.unmount();
    }

    wrappers.length = 0;
    document.body.innerHTML = '';
});

describe('AppCombobox', () => {
    it('asocia la etiqueta con el input de búsqueda', () => {
        const wrapper = mountCombobox();

        const input = wrapper.get('input');
        const label = wrapper.get('label');

        expect(label.text()).toContain('Categoría');
        expect(label.attributes('for')).toBe(input.attributes('id'));
    });

    it('abre las opciones y filtra al escribir', async () => {
        const wrapper = mountCombobox();

        await wrapper.get('button[aria-label="Mostrar opciones"]').trigger('click');
        await nextTick();

        expect(document.querySelectorAll('[role="option"]')).toHaveLength(3);

        await wrapper.get('input').setValue('vivi');
        await nextTick();

        const visible = document.querySelectorAll('[role="option"]');

        expect(visible).toHaveLength(1);
        expect(visible[0]?.textContent).toContain('Vivienda');
    });

    it('emite el valor de la opción elegida', async () => {
        const wrapper = mountCombobox();

        await wrapper.get('button[aria-label="Mostrar opciones"]').trigger('click');
        await nextTick();

        const first = document.querySelector('[role="option"]') as HTMLElement;
        first.click();
        await nextTick();

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['alimentacion']);
    });

    it('muestra el texto de la opción seleccionada', () => {
        const wrapper = mountCombobox('vivienda');

        expect((wrapper.get('input').element as HTMLInputElement).value).toBe('Vivienda');
    });

    it('abre todas las opciones aunque haya una seleccionada', async () => {
        const wrapper = mountCombobox('vivienda');

        await wrapper.get('button[aria-label="Mostrar opciones"]').trigger('click');
        await nextTick();

        expect(document.querySelectorAll('[role="option"]')).toHaveLength(3);
    });

    it('permite limpiar la selección', async () => {
        const wrapper = mountCombobox('vivienda', true);

        await wrapper.get('button[aria-label="Limpiar selección"]').trigger('click');

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([null]);
    });

    it('muestra el error y lo asocia al input', () => {
        const wrapper = mount(AppCombobox, {
            attachTo: document.body,
            props: {
                modelValue: null,
                options,
                label: 'Categoría',
                error: 'Elige una categoría.',
            },
        });

        wrappers.push(wrapper);

        expect(wrapper.get('input').attributes('aria-invalid')).toBe('true');
        expect(wrapper.get('[role="alert"]').text()).toBe('Elige una categoría.');
    });
});
