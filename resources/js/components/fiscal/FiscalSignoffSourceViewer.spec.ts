import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import FiscalSignoffSourceViewer from '@/components/fiscal/FiscalSignoffSourceViewer.vue';

const store = {
    fetchSourceFiles: vi.fn(),
    fetchSourceFile: vi.fn(),
};

vi.mock('@/stores/fiscalSignoffs', () => ({
    useFiscalSignoffStore: () => store,
}));

const stage = {
    key: 'iva-and-retention-catalogs',
    version: 1,
    title: 'Catálogos de tarifas y retenciones',
    summary: 'Validar vigencias.',
    references: [],
    source_files: [{ id: 'fiscal-catalog-data', label: 'FiscalCatalogData.php', language: 'php', description: 'Catálogo.', line_count: 10, size_bytes: 100 }],
    fingerprint: 'fp',
    status: 'pending' as const,
    latest_review: null,
    history: [],
};

function mountViewer(open = true) {
    return mount(FiscalSignoffSourceViewer, {
        props: { open, stage },
        global: {
            stubs: {
                Modal: {
                    props: ['title', 'description'],
                    template: '<div role="dialog"><h2>{{ title }}</h2><p>{{ description }}</p><slot name="body" /><slot name="footer" /></div>',
                },
                Button: {
                    props: ['label'],
                    template: '<button>{{ label }}</button>',
                },
            },
        },
    });
}

describe('FiscalSignoffSourceViewer', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        store.fetchSourceFiles.mockResolvedValue([
            { id: 'fiscal-catalog-data', label: 'FiscalCatalogData.php', language: 'php', description: 'Catálogo.', line_count: 2, size_bytes: 100 },
        ]);
        store.fetchSourceFile.mockResolvedValue({
            id: 'fiscal-catalog-data',
            label: 'FiscalCatalogData.php',
            language: 'php',
            description: 'Catálogo.',
            content: 'linea uno\nlinea dos',
            line_count: 2,
            size_bytes: 100,
            sha256: 'a'.repeat(64),
        });
    });

    it('loads and displays source files', async () => {
        const wrapper = mountViewer(true);
        await vi.waitFor(() => expect(store.fetchSourceFiles).toHaveBeenCalled());
        await nextTick();
        await nextTick();

        expect(wrapper.text()).toContain('FiscalCatalogData.php');
        expect(wrapper.text()).toContain('linea uno');
        expect(wrapper.text()).toContain('linea dos');
        expect(wrapper.text()).toContain('SHA-256');
    });

    it('shows empty state when there are no files', async () => {
        store.fetchSourceFiles.mockResolvedValue([]);
        const wrapper = mountViewer(true);
        await vi.waitFor(() => expect(store.fetchSourceFiles).toHaveBeenCalled());
        await nextTick();
        await nextTick();

        expect(wrapper.text()).toContain('No hay código fuente disponible');
    });

    it('shows an error when loading fails', async () => {
        store.fetchSourceFiles.mockRejectedValue(new Error('Fallo de red'));
        const wrapper = mountViewer(true);
        await vi.waitFor(() => expect(store.fetchSourceFiles).toHaveBeenCalled());
        await nextTick();
        await nextTick();

        expect(wrapper.text()).toContain('Fallo de red');
    });
});
