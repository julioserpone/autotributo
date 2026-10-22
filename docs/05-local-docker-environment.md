# 05 — Entorno local con Docker

## 1. Requisitos

| Herramienta | Versión mínima | Notas |
|---|---|---|
| Docker Desktop / OrbStack | 4.x / 2.x | 8 GB de RAM asignados recomendado |
| Docker Compose | v2 | Incluido en Docker Desktop |
| Make | 3.81 | macOS/Linux |
| Git | 2.40 | |
| Node (host, opcional) | 24 | Solo para Playwright y herramientas puntuales |

No es necesario instalar PHP, Composer ni Postgres en el host: todo corre en contenedores.

## 2. Servicios

| Servicio | Imagen | Puerto host | Uso |
|---|---|---|---|
| `app` | `docker/php/Dockerfile` (PHP 8.5-FPM) | — | Aplicación Laravel |
| `web` | `nginx:1.27-alpine` | `8000` | Servidor HTTP |
| `queue` | misma imagen | — | Worker de colas (`sri,ai,reports,default`) |
| `scheduler` | misma imagen | — | `schedule:work` |
| `reverb` | misma imagen | `8080` | WebSockets |
| `vite` | `node:24-alpine` | `5173` | HMR / build (configurable con `VITE_PORT`) |
| `db` | `postgres:16-alpine` | `5433` | Base de datos |
| `valkey` | `valkey/valkey:8-alpine` | `6380` | Caché, sesiones, colas |
| `mailpit` | `axllent/mailpit` | `8026`, `1026` | Correo de desarrollo (UI 8026; SMTP del host 1026 → `mailpit:1025` dentro de Docker) |

> No hay contenedor S3: en local se usa el **disco** (`storage/app/private`) por defecto. Si necesitas probar el flujo real de object storage, apunta a **AWS S3** con un bucket de ambiente (`autotributo-local-app`) o usa `Storage::fake()` en tests. Ver `docs/aws-s3-setup.md`.

## 3. Primer arranque

```bash
git clone <repo> autotributo && cd autotributo
cp .env.example .env

# 1) Levantar infraestructura
make up

# 2) Dependencias, clave y base de datos
make install

# 3) Abrir la app
open http://localhost:8000
```

> El repositorio ya incluye el esqueleto de F1-01 (Laravel 13 + SPA Vue/TS). Si el puerto `5173` está ocupado por otro proyecto, agrega `VITE_PORT=5174` al `.env` antes de `make up`; Compose y Vite usarán ese puerto.

## 4. URLs y tenants en local

| Recurso | URL |
|---|---|
| Aplicación (tenant único) | http://localhost:8000 |
| Vite (HMR) | http://localhost:5173 (o `VITE_PORT`) |
| Reverb | ws://localhost:8080 |
| Storage local | `storage/app/private` (bind mount del repositorio) |
| Mailpit (UI) | http://localhost:8026 |
| Mailpit (SMTP desde el host) | `localhost:1026` (la app usa `mailpit:1025`) |
| PostgreSQL | `localhost:5433` (`autotributo` / `secret`) |
| Valkey | `localhost:6380` |

### 4.1 Almacenamiento por ambiente

| Escenario | `FILESYSTEM_DISK` | Ubicación / bucket | Notas |
|---|---|---|---|
| App local diaria | `local` | `storage/app/private` | Por defecto; sin contenedores ni credenciales |
| Tests Pest | `local` | `storage/framework/testing/disks` | Rápido y determinista; nunca AWS real |
| Prueba manual de S3 | `s3` | `autotributo-local-app` (+ `-sri`) | Opcional; valida IAM, SSE y lifecycle |
| Staging | `s3` | `autotributo-staging-app` (+ `-sri`) | Credenciales y bucket distintos de producción |
| Producción | `s3` | `autotributo-prod-app` (+ `-sri`) | IAM mínimo, SSE, versionado y lifecycle |

Para usar AWS S3 en local, en `.env`: `FILESYSTEM_DISK=s3`, descomenta `AWS_*`, deja `AWS_ENDPOINT` vacío, `AWS_USE_PATH_STYLE_ENDPOINT=false` y usa un usuario IAM del ambiente local. **Nunca** uses credenciales de producción en tu máquina. Detalle en `docs/aws-s3-setup.md`.

**Tenant en local:** el MVP es single-tenant, así que se entra por `http://localhost:8000`; no hay subdominios. `TENANCY_MODE=single` liga el tenant `demo` (seed) en cada petición. El modo multi (futuro) usará `demo.localhost`; si tu navegador no resuelve `*.localhost`, agrega:

```
127.0.0.1 demo.localhost acme.localhost
```

en `/etc/hosts`, o usa `lvh.me` (`http://demo.lvh.me:8000`).

## 5. Comandos habituales

```bash
make up                # levantar
make down              # detener
make ps                # estado/healthchecks
make logs              # logs de todos
make shell             # shell en el contenedor app
make art cmd="route:list"
make composer cmd="require foo/bar"
make npm cmd="install pinia"
make migrate
make fresh             # DESTRUCTIVO: migra desde cero + seed
make test              # suite completa en paralelo
make test-unit
make check             # Pint + PHPStan + tests (equivalente a CI)
make psql
make redis
make storage-url
make mailpit-url
make queue-logs
make reverb-logs
make reset             # DESTRUCTIVO: borra contenedores y volúmenes
```

## 6. Depuración

**Xdebug (opcional):**

```bash
INSTALL_XDEBUG=true docker compose build app
# .env
XDEBUG_MODE=debug
XDEBUG_CONFIG="client_host=host.docker.internal"
```

Configura el IDE en el puerto `9003` con path mapping `/var/www/html` → ruta del repo.

**Vite/HMR en Docker:** el contenedor publica `${VITE_PORT:-5173}`. `vite.config.ts` lee `VITE_PORT` para el servidor y el HMR; si el HMR no conecta, revisa que el `.env` tenga el mismo valor y que el puerto esté libre.

```ts
const vitePort = Number(process.env.VITE_PORT ?? 5173);

server: {
  host: '0.0.0.0',
  port: vitePort,
  strictPort: true,
  hmr: { host: 'localhost', protocol: 'ws', port: vitePort },
}
```

**Rendimiento en macOS:** el bind mount con `:cached` es suficiente para este proyecto. Si la E/S se vuelve lenta (p. ej. `composer install` de 2 min), usa OrbStack o sincronización tipo Mutagen; no es necesario para el día a día.

## 7. Problemas frecuentes

| Síntoma | Causa / solución |
|---|---|
| Nginx 502 | `php-fpm` no arrancó o la app lanzó un error: `make ps`, `make logs`, revisa `storage/logs/laravel.log` |
| `Permission denied` en `storage/` | El compose ejecuta los contenedores con tu `UID/GID`. Verifica `id -u` y `id -g`, y reconstruye con `make build` |
| `could not translate host name "db"` | Estás ejecutando `php artisan` en el host: usa `make art` |
| Puerto 8000 ocupado | Cambia el mapeo en `docker-compose.yml` (`"8001:80"`) o detén el proceso |
| Puerto 5173 ocupado | Agrega `VITE_PORT=5174` al `.env` y ejecuta `make up`; Vite y Compose usan ese puerto |
| Postgres en `localhost:5432` ya existe | Usamos `5433` justamente para evitarlo |
| Vite no compila tras `npm install` | `make restart` y revisa `make logs`; el contenedor limpia `node_modules/.vite` al arrancar |
| Reverb se desconecta | Verifica `REVERB_HOST/PORT` y que `8080` esté libre; en local el host es `localhost`, no `reverb` |
| Tests lentos | `pest --parallel`; en Docker, 4 procesos por defecto. Ajusta con `--processes=4` |
| `NoSuchBucket` o 403 con AWS S3 | Verifica `AWS_BUCKET` del ambiente, `AWS_DEFAULT_REGION`, credenciales IAM y que el bucket exista. En local no reutilices credenciales de producción |
| No aparecen los archivos en local | `FILESYSTEM_DISK=local`; están en `storage/app/private` (`make shell` para inspeccionar) |

## 8. Datos de demo

El seeder `DemoTenantSeeder` crea:
- Tenant `demo` (slug `demo`, acceso por `http://localhost:8000` en modo single), plan `pro`.
- Usuarios `owner@demo.test` / `contador@demo.test` con contraseña `password` (solo local).
- Dos empresas con RUC ficticios válidos.
- 50 comprobantes de fixture (facturas, NC, retenciones) en el período actual.
- Reglas de clasificación de ejemplo.

Nunca se ejecuta en staging ni producción:

```php
// DatabaseSeeder.php
if (app()->environment('local', 'testing')) {
    $this->call(DemoTenantSeeder::class);
}
```

## 9. Paridad con Laravel Cloud

| Aspecto | Local | Cloud |
|---|---|---|
| PHP | 8.5 (Docker) | 8.5 (runtime gestionado) |
| Extensiones | `pdo_pgsql`, `redis`, `intl`, `bcmath`, `gd`, `zip`, `pcntl`; OPcache incluido en la imagen | Deben validarse en F1; se solicita soporte si falta alguna |
| Base de datos | Postgres 16 | Serverless Postgres 16/17/18 |
| Colas | Redis + `queue:work` | SQS administrado (`QUEUE_CONNECTION=cloud`) |
| WebSockets | Reverb en contenedor | Cluster Reverb administrado |
| Storage | Disco local por defecto; AWS S3 opcional (`autotributo-local-app`) | AWS S3 (bucket por ambiente) |
| Correo | Mailpit | Resend |
| Servidor web | Nginx + PHP-FPM | Runtime gestionado (Octane/FPM según entorno) |
| Archivos | Bind mount persistente | Filesystem efímero |

**Regla:** ninguna funcionalidad puede depender de disco local persistente ni de procesos en segundo plano dentro del contenedor web.

## 10. Limpieza

```bash
make down          # conserva datos
make reset         # borra contenedores y volúmenes (base de datos incluida)
docker system prune -f   # opcional: libera imágenes no usadas
```
