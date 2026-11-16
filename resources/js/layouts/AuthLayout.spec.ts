import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import AuthLayout from '@/layouts/AuthLayout.vue';

describe('AuthLayout', () => {
    it('muestra el título y el contenido', () => {
        const wrapper = mount(AuthLayout, {
            props: { title: 'Iniciar sesión', subtitle: 'Accede con tus credenciales.' },
            slots: { default: '<p>Formulario</p>' },
        });

        expect(wrapper.get('h1').text()).toBe('Iniciar sesión');
        expect(wrapper.text()).toContain('Formulario');
        expect(wrapper.text()).toContain('Accede con tus credenciales.');
    });
});
