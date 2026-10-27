# 09 — Estado de avance

> Tabla viva del proyecto. Se actualiza al cerrar cada tarea (una tarea = un commit/PR). La fuente de verdad del alcance sigue siendo `docs/02-implementation-plan.md`.

**Última actualización:** 2026-10-27

## Resumen por fase

| Fase | Estado | Avance | Último hito |
|---|---|---|---|
| F0 · Descubrimiento | Completado | 9/9 | Decisiones, ADRs y entorno local |
| F1 · Núcleo tenant-ready | En curso | 2/11 | F1-02 · Tenancy-ready y aislamiento por tenant |
| F2 · Ingesta SRI | En curso | 6/12 | F2-06 · Parser XML completo vía `sri-comprobantes-php` v0.2.0 |
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
| F2-01 | Puerto `SriGateway` | Completado | `ce98289` · `sri-comprobantes-php` v0.1.2 · `SriAuthorizationGateway`, `SriRobotGateway`, adaptadores package/Lambda y fakes · 51 tests, 98.0% cobertura |
| F2-02 | Layout S3 del robot | Completado | `6abd600` · `SriRobotObjectPath`, disco `sri`, `SriRobotObjectStore` con SHA-256, layout `{ambiente}/{ruc}/{periodo}` · 61 tests, 97.7% cobertura |
| F2-03 | `SriSyncRun` y orquestación | Completado | `23c9ac6` · `SriSyncRun`, `SriSyncRunFile`, `StartSriSync`, estados/transiciones idempotentes y dispatch al robot · tests de feature y unit |
| F2-04 | Callback firmado | Completado | `POST /api/v1/webhooks/sri-robot` con HMAC-SHA256, ventana temporal, límite de tamaño y dedupe por `event_id`; `sri_robot_callbacks`, correlación por `callback_event_id` y `event_id`/`callback_url` en la invocación · tests de firma, expiración, duplicado, tamaño y transición · validado end-to-end desde `sri-txt-robot` en AWS: invocación real, callback `completed` con `202` y run en `downloading` |
| F2-05 | Reconciliación | Completado | `f5f82e9` · `periods`, `sri_sync_run_files`, `ReconcileSriRun(s)`, `sri:reconcile-runs`, scheduler `*/10`, validación de manifiesto/TXT/claves y transición a `parsing` · 12 tests F2-05 · suite 107 tests / 310 assertions |
| F2-06 | Parser XML | Completado | Paquete `sri-comprobantes-php` **v0.2.0** (`ComprobanteReader`: 01, 03, 04, 05, 06, 07, PR #5) consumido vía `SriInvoiceParser` + `PackageSriInvoiceParser` · XXE-safe, centavos, `totalsMismatch` sin corregir, `SriRetentionData` · parser local eliminado |
| F2-07 | Parser de manifiesto/TXT | Completado | `SriAccessKey` (verificador delegado a `AccessKeyGenerator` del paquete), `AccessKeyListParser`, `PeriodBatchBuilder`, `ParseSriRun` y `sri:parse-run` · run `completed`/`partial`/`failed` e idempotencia |
| F2-08 | Persistencia idempotente | Pendiente | — |
| F2-09 | Progreso en vivo | Pendiente | — |
| F2-10 | Resiliencia | Pendiente | — |
| F2-11 | Límites y cuotas | Pendiente | — |
| F2-12 | Observabilidad | Pendiente | — |

## Cómo actualizar

1. Cambiar el estado de la tarea a `Completado` y añadir commit + evidencia verificable.
2. Actualizar el avance de la fase en el resumen.
3. No marcar una tarea como completada si sus pruebas no están en verde o falta el criterio de salida de `docs/02-implementation-plan.md`.
