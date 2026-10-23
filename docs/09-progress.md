# 09 — Estado de avance

> Tabla viva del proyecto. Se actualiza al cerrar cada tarea (una tarea = un commit/PR). La fuente de verdad del alcance sigue siendo `docs/02-implementation-plan.md`.

**Última actualización:** 2026-10-22

## Resumen por fase

| Fase | Estado | Avance | Último hito |
|---|---|---|---|
| F0 · Descubrimiento | Completado | 9/9 | Decisiones, ADRs y entorno local |
| F1 · Núcleo tenant-ready | En curso | 2/11 | F1-02 · Tenancy-ready y aislamiento por tenant |
| F2 · Ingesta SRI | En curso | 1/12 | F2-01 · Puertos SRI y paquete `sri-comprobantes-php` |
| F3 · Motor fiscal + IA | Pendiente | 0/9 | — |
| F4 · Frontend | Pendiente | 0/12 | — |
| F5 · Declaraciones | Pendiente | 0/12 | — |
| F6 · Hardening y beta | Pendiente | 0/8 | — |

## Fase 1 · Núcleo tenant-ready, identidad y empresas

| ID | Tarea | Estado | Evidencia |
|---|---|---|---|
| F1-01 | Esqueleto Laravel 13 + SPA | Completado | `042d1bf` · Pest, PHPStan 8, Pint, Vitest, E2E y 100% cobertura |
| F1-02 | Tenancy-ready | Completado | `bcf5896` · `stancl/tenancy` 3.10.1 sin DB por tenant · TenantContext, TenantScope, BelongsToTenant, TenantAware, caché/storage prefijados · 30 tests, 98.2% cobertura |
| F1-03 | Identidad | Pendiente | — |
| F1-04 | Autorización | Pendiente | — |
| F1-05 | Empresas | Pendiente | — |
| F1-06 | Credenciales SRI | Pendiente | — |
| F1-07 | Auditoría base | Pendiente | — |
| F1-08 | Reverb | Pendiente | — |
| F1-09 | Convenciones de API | Pendiente | — |
| F1-10 | Provisión inicial | Pendiente | — |
| F1-11 | Deploy a staging | Pendiente | — |

## Fase 2 · Ingesta SRI

| ID | Tarea | Estado | Evidencia |
|---|---|---|---|
| F2-01 | Puerto `SriGateway` | Completado | `sri-comprobantes-php` v0.1.2 · `SriAuthorizationGateway`, `SriRobotGateway`, adaptadores package/Lambda y fakes · 51 tests, 98.0% cobertura |
| F2-02 | Layout S3 del robot | Pendiente | — |
| F2-03 | `SriSyncRun` y orquestación | Pendiente | — |
| F2-04 | Callback firmado | Pendiente | Bloqueado: `sri-txt-robot` aún no emite callback |
| F2-05 | Reconciliación | Pendiente | — |
| F2-06 | Parser XML | Pendiente | — |
| F2-07 | Parser de manifiesto/TXT | Pendiente | — |
| F2-08 | Persistencia idempotente | Pendiente | — |
| F2-09 | Progreso en vivo | Pendiente | — |
| F2-10 | Resiliencia | Pendiente | — |
| F2-11 | Límites y cuotas | Pendiente | — |
| F2-12 | Observabilidad | Pendiente | — |

## Cómo actualizar

1. Cambiar el estado de la tarea a `Completado` y añadir commit + evidencia verificable.
2. Actualizar el avance de la fase en el resumen.
3. No marcar una tarea como completada si sus pruebas no están en verde o falta el criterio de salida de `docs/02-implementation-plan.md`.
