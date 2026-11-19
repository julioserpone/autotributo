import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import AppPage from '@/components/layout/AppPage.vue';

vi.mock('@/stores/health', () => ({
    useHealthStore: () => ({ status: 'ok', check: vi.fn() }),
}));

describe('AppPage', () => {
    let pinia: ReturnType<typeof createPinia>;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
    });

    it('renderiza el título, la descripción, las acciones y el contenido', async () => {
        const router = createRouter({
            history: createMemoryHistory(),
            routes: [{ path: '/', name: 'dashboard', component: { template: '<div />' } }],
        });
        await router.push('/');

        const wrapper = mount(AppPage, {
            props: { title: 'Panel Tributario', description: 'Resumen del período.' },
            slots: {
                default: '<p>Contenido</p>',
                actions: '<button type="button">Acción</button>',
            },
            global: {
                plugins: [pinia, router],
            },
        });

        expect(wrapper.get('h1').text()).toBe('Panel Tributario');
        expect(wrapper.text()).toContain('Resumen del período.');
        expect(wrapper.text()).toContain('Contenido');
        expect(wrapper.text()).toContain('Acción');
        expect(wrapper.get('main').attributes('id')).toBe('contenido');
    });
});
