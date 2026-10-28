import { fileURLToPath, URL } from 'node:url';

import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vite';
import type { Plugin } from 'vite';
import laravel from 'laravel-vite-plugin';

const vitePort = Number(process.env.VITE_PORT ?? 5173);

// Vite 6+ solo acepta orígenes de localhost en el servidor de desarrollo. Al
// abrir el sitio por un túnel (Expose, ngrok, Cloudflare…) el navegador bloquea
// `@vite/client` y `app.ts` por CORS. Aquí se autorizan además los dominios del
// túnel; se pueden añadir otros con VITE_TUNNEL_DOMAINS (separados por comas).
const dominiosTunel = (process.env.VITE_TUNNEL_DOMAINS ?? 'sharedwithexpose.com')
    .split(',')
    .map((dominio) => dominio.trim())
    .filter(Boolean)
    .map((dominio) => dominio.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));

const origenPermitido = new RegExp(
    `^https?://(([a-z0-9-]+\\.)*(${dominiosTunel.join('|')})|localhost|127\\.0\\.0\\.1|\\[::1\\])(:\\d+)?$`,
    'i',
);

// Chrome además aplica Private Network Access: un sitio público (el túnel) no
// puede pedir recursos a localhost salvo que el preflight lo autorice. Vite
// resuelve el preflight antes de los middlewares de los plugins, así que se le
// pide continuar (`preflightContinue`) y aquí se añade la cabecera y se cierra.
const redPrivada: Plugin = {
    name: 'dev-red-privada',
    configureServer(servidor) {
        servidor.middlewares.use((req, res, next) => {
            if (req.method !== 'OPTIONS') {
                next();

                return;
            }

            if (req.headers['access-control-request-private-network'] === 'true') {
                res.setHeader('Access-Control-Allow-Private-Network', 'true');
            }

            res.statusCode = 204;
            res.setHeader('Content-Length', '0');
            res.end();
        });
    },
};

// URL pública del túnel (Expose y similares). Cuando se define, Laravel genera
// las rutas de los assets con ese origen (nginx los reenvía al servidor de
// Vite) y el HMR viaja por wss. Sin ella todo sigue apuntando a localhost.
const urlTunel = (process.env.VITE_TUNNEL_URL ?? '').trim().replace(/\/+$/, '');
const tunel = urlTunel ? new URL(urlTunel) : null;
const segura = tunel?.protocol === 'https:';
const puertoTunel = tunel ? Number(tunel.port || (segura ? 443 : 80)) : 0;

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        vue(),
        tailwindcss(),
        redPrivada,
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    server: {
        host: '0.0.0.0',
        port: vitePort,
        strictPort: true,
        // Sin esto, el túnel sirve el HTML pero los assets no cargan.
        allowedHosts: dominiosTunel.map((dominio) => `.${dominio}`),
        cors: {
            origin: origenPermitido,
            preflightContinue: true,
        },
        ...(urlTunel ? { origin: urlTunel } : {}),
        ws: tunel
            ? {
                  host: tunel.hostname,
                  protocol: segura ? 'wss' : 'ws',
                  clientPort: puertoTunel,
                  path: '/@vite-hmr',
              }
            : {
                  host: 'localhost',
                  protocol: 'ws',
                  port: vitePort,
              },
    },
});
