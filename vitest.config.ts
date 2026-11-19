import { fileURLToPath, URL } from 'node:url';

import ui from '@nuxt/ui/vite';
import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [vue(), ui({ colorMode: false })],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        include: ['resources/js/**/*.spec.ts'],
        setupFiles: ['./resources/js/test/setup.ts'],
        css: false,
        // El entorno jsdom con Nuxt UI tarda en montar overlays y portales;
        // el valor por defecto (5 s) provoca fallos intermitentes en CI lento.
        testTimeout: 20000,
        hookTimeout: 20000,
    },
});
