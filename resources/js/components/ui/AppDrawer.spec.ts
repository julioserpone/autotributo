import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
import { afterEach, describe, expect, it } from 'vitest';

import AppDrawer from '@/components/ui/AppDrawer.vue';

const wrappers: VueWrapper[] = [];

async function mountDrawer(options: { side?: 'left' | 'right'; dismissible?: boolean } = {}): Promise<VueWrapper> {
    const wrapper = mount(AppDrawer, {
        attachTo: document.body,
        props: {
            open: true,
            title: 'Detalle del comprobante',
            description: 'Factura 001-001-000000123',
            side: options.side ?? 'right',
            dismissible: options.dismissible ?? true,
        },
        slots: {
            default: '<p>Proveedor: Ferretería Central</p>',
            footer: '<button type="button">Cerrar panel</button>',
        },
    });

    wrappers.push(wrapper);

    // El portal de reka-ui se monta tras el primer render.
    await nextTick();

    return wrapper;
}

afterEach(() => {
    for (const wrapper of wrappers) {
        wrapper.unmount();
    }

    wrappers.length = 0;
    document.body.innerHTML = '';
});

describe('AppDrawer', () => {
    it('se abre por la derecha con título, contenido y pie', async () => {
        await mountDrawer();

        const dialog = document.querySelector('[role="dialog"]');
        const panel = dialog?.querySelector('[role="document"]');

        expect(dialog).not.toBeNull();
        expect(dialog?.textContent).toContain('Detalle del comprobante');
        expect(dialog?.textContent).toContain('Proveedor: Ferretería Central');
        expect(dialog?.textContent).toContain('Cerrar panel');
        expect(panel?.className).toContain('border-l');
    });

    it('se abre por la izquierda', async () => {
        await mountDrawer({ side: 'left' });

        const panel = document.querySelector('[role="document"]');

        expect(panel?.className).toContain('border-r');
    });

    it('emite update:open al cerrar', async () => {
        const wrapper = await mountDrawer();

        const close = document.querySelector('button[aria-label="Cerrar"]') as HTMLButtonElement;
        close.click();
        await nextTick();

        expect(wrapper.emitted('update:open')?.[0]).toEqual([false]);
    });

    it('oculta el cierre cuando no es descartable', async () => {
        await mountDrawer({ dismissible: false });

        expect(document.querySelector('button[aria-label="Cerrar"]')).toBeNull();
    });
});
