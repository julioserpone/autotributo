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
| `vite` | `node:24-alpine` | `5173` | HMR / build |
| `db` | `postgres:16-alpine` | `5433` | Base de datos |
| `valkey` | `valkey/valkey:8-alpine` | `6380` | Caché, sesiones, colas |
| `minio` | `minio/minio` | `9000`, `9001` | S3 local |
| `minio-init` | `minio/mc` | — | Crea buckets al arrancar |
| `mailpit` | `axllent/mailpit` | `8025`, `1025` | Correo de desarrollo |

## 3. Primer arranque

```bash
git clone <repo> autotributo && cd autotributo
cp .env.example .env

# 1) Levantar infraestructura
make up

# 2) Crear el esqueleto Laravel (solo la primera vez, Fase 1)
#    El repositorio llega sin aplicación hasta F1-01.
docker compose exec app composer create-project laravel/laravel . --prefer-dist
#    (si el directorio no está vacío, crear en temporal y mover)

# 3) Dependencias y base de datos
make install

# 4) Abrir la app
open http://localhost:8000
```

> Mientras no exista `composer.json`, Nginx devolverá **502**: es esperado. El plan contempla crear el esqueleto en la tarea `F1-01`.

## 4. URLs y tenants en local

| Recurso | URL |
|---|---|
| Aplicación | http://localhost:8000 |
| Tenant demo | http://demo.localhost:8000 |
| Vite (HMR) | http://localhost:5173 |
| Reverb | ws://localhost:8080 |
| MinIO API / Consola | http://localhost:9000 · http://localhost:9001 |
| Mailpit | http://localhost:8025 |
| PostgreSQL | `localhost:5433` (`autotributo` / `secret`) |
| Valkey | `localhost:6380` |

**Resolución de tenant:** el dominio raíz es `localhost`, por lo que `demo.localhost` funciona en navegadores modernos sin editar `/etc/hosts`. Si tu navegador no resuelve `*.localhost`, agrega:

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

**Vite/HMR en Docker:** el contenedor publica `5173`; si el HMR no conecta, revisa que `vite.config.ts` use:

```ts
server: {
  host: '0.0.0.0',
  port: 5173,
  strictPort: true,
  hmr: { host: 'localhost', protocol: 'ws', port: 5173 },
}
```

**Rendimiento en macOS:** el bind mount con `:cached` es suficiente para este proyecto. Si la E/S se vuelve lenta (p. ej. `composer install` de 2 min), usa OrbStack o sincronización tipo Mutagen; no es necesario para el día a día.

## 7. Problemas frecuentes

| Síntoma | Causa / solución |
|---|---|
| Nginx 502 | `composer.json` aún no existe o `php-fpm` no arrancó: `make ps`, `make logs` |
| `Permission denied` en `storage/` | El compose ejecuta los contenedores con tu `UID/GID`. Verifica `id -u` y `id -g`, y reconstruye con `make build` |
| `could not translate host name "db"` | Estás ejecutando `php artisan` en el host: usa `make art` |
| Puerto 8000 ocupado | Cambia el mapeo en `docker-compose.yml` (`"8001:80"`) o detén el proceso |
| Postgres en `localhost:5432` ya existe | Usamos `5433` justamente para evitarlo |
| Vite no compila tras `npm install` | `make restart` y revisa `make logs`; a veces el contenedor arrancó antes del mount |
| Reverb se desconecta | Verifica `REVERB_HOST/PORT` y que `8080` esté libre; en local el host es `localhost`, no `reverb` |
| Tests lentos | `pest --parallel`; en Docker, 4 procesos por defecto. Ajusta con `--processes=4` |
| MinIO 403 al subir | `AWS_USE_PATH_STYLE_ENDPOINT=true` y endpoint `http://minio:9000` (desde el contenedor) |

## 8. Datos de demo

El seeder `DemoTenantSeeder` crea:
- Tenant `demo` (`demo.localhost`), plan `pro`.
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
| Extensiones | `pdo_pgsql`, `redis`, `intl`, `bcmath`, `gd`, `zip`, `pcntl`, `opcache` | Deben validarse en F1; se solicita soporte si falta alguna |
| Base de datos | Postgres 16 | Serverless Postgres 16/17/18 |
| Colas | Redis + `queue:work` | SQS administrado (`QUEUE_CONNECTION=cloud`) |
| WebSockets | Reverb en contenedor | Cluster Reverb administrado |
| Storage | MinIO | Object Storage S3 |
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
