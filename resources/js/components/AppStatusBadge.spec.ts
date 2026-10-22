import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import AppStatusBadge from '@/components/AppStatusBadge.vue';

describe('AppStatusBadge', () => {
    it('muestra el estado de la API', () => {
        const wrapper = mount(AppStatusBadge, {
            props: { status: 'ok' },
        });

        expect(wrapper.text()).toContain('API conectada');
        expect(wrapper.find('[data-status="ok"]').exists()).toBe(true);
    });

    it('muestra el error cuando la API no responde', () => {
        const wrapper = mount(AppStatusBadge, {
            props: { status: 'error' },
        });

        expect(wrapper.text()).toContain('API no disponible');
    });
});
