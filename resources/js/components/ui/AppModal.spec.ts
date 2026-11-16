import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
import { afterEach, describe, expect, it } from 'vitest';

import AppModal from '@/components/ui/AppModal.vue';

const wrappers: VueWrapper[] = [];

async function mountModal(
    options: { open?: boolean; dismissible?: boolean; description?: string } = {},
): Promise<VueWrapper> {
    const wrapper = mount(AppModal, {
        attachTo: document.body,
        props: {
            open: options.open ?? true,
            title: 'Confirmar revisión',
            description: options.description ?? 'La decisión se guardará en la auditoría.',
            dismissible: options.dismissible ?? true,
        },
        slots: {
            default: '<p>¿Aceptar la categoría sugerida?</p>',
            footer: '<button type="button">Aceptar</button>',
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

describe('AppModal', () => {
    it('renderiza el diálogo en un portal con título y contenido', async () => {
        await mountModal();

        const dialog = document.querySelector('[role="dialog"]');

        expect(dialog).not.toBeNull();
        expect(dialog?.textContent).toContain('Confirmar revisión');
        expect(dialog?.textContent).toContain('¿Aceptar la categoría sugerida?');
        expect(document.body.textContent).toContain('La decisión se guardará en la auditoría.');
    });

    it('emite update:open al cerrar con el botón', async () => {
        const wrapper = await mountModal();

        const close = document.querySelector('button[aria-label="Cerrar"]') as HTMLButtonElement;
        close.click();
        await nextTick();

        expect(wrapper.emitted('update:open')?.[0]).toEqual([false]);
    });

    it('oculta el cierre cuando no es descartable', async () => {
        await mountModal({ dismissible: false });

        expect(document.querySelector('button[aria-label="Cerrar"]')).toBeNull();
    });

    it('cierra con Escape', async () => {
        const wrapper = await mountModal();

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await nextTick();

        expect(wrapper.emitted('update:open')?.[0]).toEqual([false]);
    });

    it('no renderiza el diálogo cuando está cerrado', async () => {
        await mountModal({ open: false });

        expect(document.querySelector('[role="dialog"]')).toBeNull();
    });
});
