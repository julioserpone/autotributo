# Autotributo

Sistema de automatización contable para Ecuador (**SRI**): ingesta de comprobantes electrónicos, clasificación semántica de ítems con IA, auditoría fiscal preventiva y generación asistida de formularios **104 (IVA)**, **103 (retenciones)**, **102/101 (Renta)**.

Orientado a estudios contables y contadores independientes. El MVP es **single-tenant** (un estudio por despliegue) con el esquema preparado para activar multitenancy sin migrar datos (ADR-0006).

> **Estado:** planificación. Este repositorio contiene el plan de implementación, las decisiones de arquitectura y el entorno local Docker. El código de la aplicación se construye siguiendo `docs/02-implementation-plan.md` (Fase 1 en adelante).

---

## Stack objetivo

| Capa | Tecnología | Notas |
|---|---|---|
| Backend | Laravel 13.x / PHP 8.5 | API versionada (`/api/v1`) + SPA servida por el mismo deploy |
| Tenancy | `stancl/tenancy` ^3.10 + `TENANCY_MODE=single` | Base compartida + `tenant_id` (ADR-0001/0006); multi se activa por configuración |
| Base de datos | PostgreSQL 16 | JSONB, índices GIN, RLS como defensa en profundidad |
| Caché / colas | Valkey (Redis-compatible) | En Cloud: `QUEUE_CONNECTION=cloud` (SQS administrado) |
| Tiempo real | Laravel Reverb | En Cloud: cluster administrado; local: contenedor propio |
| Ingesta SRI | AWS Lambda `julioserpone/sri-txt-robot` | Invocación asíncrona + S3 + callback firmado |
| Frontend | Vue 3 + TypeScript + Vite + Tailwind 4 + shadcn-vue | Pinia, TanStack Query, Chart.js, Echo |
| Calidad | Pest 4, PHPStan/Larastan nivel 8, Pint, Vitest, Playwright | Gates obligatorios en CI |
| Deploy | Laravel Cloud + AWS S3 | Build/deploy commands, colas administradas, Scheduler; S3 en cuenta propia con bucket por ambiente |

## Documentos

| Documento | Contenido |
|---|---|
| [`docs/01-architecture.md`](docs/01-architecture.md) | Arquitectura, stack, multitenancy, contratos, seguridad y NFRs |
| [`docs/02-implementation-plan.md`](docs/02-implementation-plan.md) | **Plan maestro por fases**: tareas, criterios de aceptación, pruebas y riesgos |
| [`docs/03-data-model.md`](docs/03-data-model.md) | Tablas, columnas, índices, invariantes y migraciones |
| [`docs/04-testing-strategy.md`](docs/04-testing-strategy.md) | Pirámide de pruebas, casos obligatorios, fixtures y gates de cobertura |
| [`docs/05-local-docker-environment.md`](docs/05-local-docker-environment.md) | Docker Compose, Makefile, troubleshooting |
| [`docs/06-laravel-cloud-deployment.md`](docs/06-laravel-cloud-deployment.md) | Build/deploy, variables, colas, storage, backups, runbooks |
| [`docs/07-governance-and-best-practices.md`](docs/07-governance-and-best-practices.md) | Git, revisión, estándares de código, ADRs, seguridad |
| [`docs/08-mvp-backlog.md`](docs/08-mvp-backlog.md) | Historias de usuario y criterios de aceptación (Gherkin) |
| [`docs/aws-s3-setup.md`](docs/aws-s3-setup.md) | Buckets por ambiente, IAM, cifrado, lifecycle y verificación de S3 |
| [`docs/fiscal-signoff.md`](docs/fiscal-signoff.md) | Registro de validación fiscal firmado por el contador |
| [`docs/adr/`](docs/adr/) | Registros de decisión de arquitectura |

## Inicio rápido (cuando exista el esqueleto de la Fase 1)

```bash
cp .env.example .env
make up            # levanta app, nginx, Postgres, Valkey, Reverb, Vite
make install       # composer install + npm ci + key:generate + migrate --seed
open http://localhost:8000
```

Servicios locales: app `http://localhost:8000`, Vite `http://localhost:5173`, Mailpit `http://localhost:8026`, Reverb `ws://localhost:8080`. El almacenamiento local usa el disco (`storage/app/private`); AWS S3 es opcional, con un bucket por ambiente.

## Principios del proyecto

1. **El aislamiento no es negociable, incluso con un solo estudio.** Todo dato de negocio lleva `tenant_id`; la suite de tests crea dos tenants y verifica que no hay acceso cruzado, para que activar el modo multi no herede fugas.
2. **La exactitud fiscal está por encima de la velocidad.** Dinero en enteros (centavos), reglas y tarifas versionadas por vigencia, cálculos cubiertos por tests de tabla.
3. **Todo es idempotente.** Las colas de Laravel Cloud garantizan *at-least-once*: cada job debe poder ejecutarse dos veces sin duplicar comprobantes ni declaraciones.
4. **Nada de lógica de negocio en controladores.** Controladores finos → Actions/Services → DTOs → modelos.
5. **La IA propone, el contador decide.** Toda clasificación automática guarda método, confianza, modelo y versión de prompt; existe revisión humana y auditoría.
6. **Nombres en inglés, producto en español.** Archivos, clases, variables, tablas y rutas se nombran en inglés; la documentación, la UI y los mensajes al usuario van en español (ver `docs/07-governance-and-best-practices.md`).
7. **Cada ambiente tiene su almacenamiento.** Local usa disco por defecto (o `autotributo-local-*` en AWS); staging y producción usan buckets distintos y credenciales IAM distintas. Nunca se comparten buckets entre ambientes.

## Aviso

Este sistema **asiste** la preparación de declaraciones; no sustituye la responsabilidad profesional del contador. Las reglas fiscales, tarifas y casilleros se cargan desde catálogos versionados y **deben validarse contra la ficha técnica vigente del SRI** antes de cada publicación.
