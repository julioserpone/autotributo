import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import AppLayout from '@/layouts/AppLayout.vue';
import { useAuthStore } from '@/stores/auth';

const { pushMock } = vi.hoisted(() => ({ pushMock: vi.fn() }));

vi.mock('vue-router', () => ({
    useRouter: () => ({ push: pushMock }),
}));

let pinia: ReturnType<typeof createPinia>;

beforeEach(() => {
    pinia = createPinia();
    setActivePinia(pinia);
    pushMock.mockReset();
});

describe('AppLayout', () => {
    it('muestra el título, el contenido y el enlace de salto', () => {
        const wrapper = mount(AppLayout, {
            props: { title: 'Panel' },
            slots: { default: '<p>Contenido del panel</p>' },
            global: { plugins: [pinia] },
        });

        expect(wrapper.get('h1').text()).toBe('Panel');
        expect(wrapper.text()).toContain('Contenido del panel');
        expect(wrapper.get('a[href="#contenido"]').text()).toContain('Saltar al contenido');
        expect(wrapper.get('main').attributes('id')).toBe('contenido');
        expect(wrapper.find('button').exists()).toBe(false);
    });

    it('muestra el botón de salir solo con sesión y cierra sesión', async () => {
        const auth = useAuthStore();
        auth.user = {
            id: 1,
            name: 'Contador',
            email: 'contador@estudio.ec',
            memberships: [{ tenant_id: 1, role: 'owner' }],
        };

        const logout = vi.spyOn(auth, 'logout').mockResolvedValue();

        const wrapper = mount(AppLayout, {
            props: { title: 'Panel' },
            global: { plugins: [pinia] },
        });

        const button = wrapper.findAll('button').find((item) => item.text() === 'Cerrar sesión');

        expect(button).toBeDefined();

        await button?.trigger('click');
        await flushPromises();

        expect(logout).toHaveBeenCalledTimes(1);
        expect(pushMock).toHaveBeenCalledWith({ name: 'login' });
    });
});
