import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import AppToastHost from '@/components/ui/AppToastHost.vue';
import { resetToasts, useToast } from '@/lib/toast';

const wrappers: VueWrapper[] = [];

function mountHost(): VueWrapper {
    const wrapper = mount(AppToastHost, { attachTo: document.body });
    wrappers.push(wrapper);

    return wrapper;
}

beforeEach(() => {
    resetToasts();
});

afterEach(() => {
    for (const wrapper of wrappers) {
        wrapper.unmount();
    }

    wrappers.length = 0;
    resetToasts();
    vi.useRealTimers();
    document.body.innerHTML = '';
});

describe('AppToastHost', () => {
    it('muestra una notificación de éxito', async () => {
        mountHost();

        useToast().success('Comprobante importado', { description: 'Factura 001-001-000000123' });
        await nextTick();

        const toast = document.querySelector('[role="status"]');

        expect(toast).not.toBeNull();
        expect(toast?.textContent).toContain('Comprobante importado');
        expect(toast?.textContent).toContain('Factura 001-001-000000123');
    });

    it('usa role alert para los errores', async () => {
        mountHost();

        useToast().error('No se pudo guardar');
        await nextTick();

        expect(document.querySelector('[role="alert"]')?.textContent).toContain('No se pudo guardar');
    });

    it('cierra la notificación a mano', async () => {
        mountHost();

        useToast().info('Sincronización iniciada');
        await nextTick();

        const close = document.querySelector('button[aria-label="Cerrar notificación"]') as HTMLButtonElement;
        close.click();
        await nextTick();
        await nextTick();

        expect(document.querySelector('[role="status"]')).toBeNull();
    });

    it('se cierra sola al cumplir la duración', async () => {
        vi.useFakeTimers();
        mountHost();

        useToast().success('Guardado', { duration: 1_000 });
        await nextTick();

        expect(document.querySelector('[role="status"]')).not.toBeNull();

        vi.advanceTimersByTime(1_000);
        await nextTick();
        await nextTick();

        expect(document.querySelector('[role="status"]')).toBeNull();
    });

    it('limita la pila a cinco notificaciones', async () => {
        mountHost();

        for (let index = 0; index < 7; index++) {
            useToast().info(`Aviso ${index}`);
        }

        await nextTick();

        expect(document.querySelectorAll('[role="status"]')).toHaveLength(5);
    });
});
