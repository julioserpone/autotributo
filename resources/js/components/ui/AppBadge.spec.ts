import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import AppBadge from '@/components/ui/AppBadge.vue';

describe('AppBadge', () => {
    it('usa el tono neutral por defecto', () => {
        const wrapper = mount(AppBadge, {
            slots: { default: 'Borrador' },
        });

        expect(wrapper.text()).toBe('Borrador');
        expect(wrapper.classes()).toContain('bg-raised');
        expect(wrapper.find('.bg-current').exists()).toBe(false);
    });

    it('aplica el color del tono y el punto de estado', () => {
        const wrapper = mount(AppBadge, {
            props: { tone: 'danger', dot: true },
            slots: { default: 'Rechazada' },
        });

        expect(wrapper.classes()).toContain('text-danger-ink');
        expect(wrapper.find('.bg-current').exists()).toBe(true);
    });

    it('respeta el tamaño grande', () => {
        const wrapper = mount(AppBadge, {
            props: { size: 'md' },
            slots: { default: 'Aceptada' },
        });

        expect(wrapper.classes()).toContain('text-sm');
    });
});
