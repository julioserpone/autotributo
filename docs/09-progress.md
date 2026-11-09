# 09 — Estado de avance

> Tabla viva del proyecto. Se actualiza al cerrar cada tarea (una tarea = un commit/PR). La fuente de verdad del alcance sigue siendo `docs/02-implementation-plan.md`.

**Última actualización:** 2026-11-09

## Resumen por fase

| Fase | Estado | Avance | Último hito |
|---|---|---|---|
| F0 · Descubrimiento | Completado | 9/9 | Decisiones, ADRs y entorno local |
| F1 · Núcleo tenant-ready | En curso | 4/11 + F1-03 al 80 % | F1-10 · Provisión inicial idempotente (#47) |
| F2 · Ingesta SRI | En curso | 11/12 | F2-12 · Observabilidad (#35) · solo falta F2-09 |
| F3 · Motor fiscal + IA | Pendiente | 0/9 | — |
| F4 · Frontend | En curso | 0/11 + fundaciones/auth parciales | Login, reset, 2FA, invitación y layout mínimo (#36–#40) |
| F5 · Declaraciones | Pendiente | 0/8 | — |
| F6 · Hardening y beta | Pendiente | 0/8 | — |

> El recuento usa las tareas de `docs/02-implementation-plan.md`. Las slices de F1-03 y las tareas nuevas (F1-03e/f/g, F2-13/14/15, F4-03b/12) se detallan abajo y no alteran el denominador de la fase.

## Fase 1 · Núcleo tenant-ready, identidad y empresas

| ID | Tarea | Estado | Prioridad | Evidencia |
|---|---|---|---|---|
| F1-01 | Esqueleto Laravel 13 + SPA | Completado | — | `042d1bf` · Pest, PHPStan 8, Pint, Vitest, E2E y 100% cobertura |
| F1-02 | Tenancy-ready | Completado | — | `bcf5896` · `stancl/tenancy` 3.10.1 sin DB por tenant · TenantContext, TenantScope, BelongsToTenant, TenantAware, caché/storage prefijados · 30 tests, 98.2% cobertura |
| F1-03 | Identidad | En curso (80 %) | P1 | Slice F1-03a (#36): `memberships`, Argon2id, login/logout/me por sesión, `LoginPage` y store de auth · Slice F1-03b (#37): recuperación y verificación de correo · Slice F1-03c (#38): Spatie Permission con teams por tenant, 2FA TOTP opcional · Slice F1-03d (#39): invitaciones con token, roles y aceptación · **Pendiente:** F1-03e sesiones revocables, F1-03f 2FA obligatorio para owner, F1-03g validaciones de invitación |
| F1-03e | Sesiones revocables y seguridad de cuenta (`US-05`) | Pendiente | P1 | Cambio de contraseña con cierre de otras sesiones; listado/revocación de sesiones; historial de inicios |
| F1-03f | 2FA obligatorio para owner (`US-03`) | Pendiente | P1 | Token de alcance limitado sin 2FA confirmado; bloqueo tras 5 intentos; registro del evento |
| F1-03g | Validaciones de invitación (`US-02`) | Pendiente | P2 | Email ya activo → 409; revocación de acceso invalida sesiones del tenant |
| F1-04 | Autorización | Completado | — | `Ability` + `RolePermissions` (matriz rol × acción) y policies `Company`, `SriSyncRun`, `Invoice`, `Membership`; defensa cross-tenant con `TenantContext` · 17 tests / 64 assertions (#46) |
| F1-05 | Empresas | Pendiente | **P0** | CRUD, validador `Ruc`, perfil tributario, archivo de empresa |
| F1-06 | Credenciales SRI | Pendiente | **P0** | Cifrado con `SRI_CREDENTIALS_KEY`, acceso auditado, rotación, borrado |
| F1-07 | Auditoría base | Pendiente | **P0** | `audit_logs` + `owen-it/laravel-auditing` en modelos sensibles |
| F1-08 | Reverb | Pendiente | P1 | Config, canales privados por tenant/empresa, autorización, estado de conexión |
| F1-09 | Convenciones de API | Pendiente | **P0** | `problem+json`, paginación, filtros, resources, OpenAPI (Scramble) |
| F1-10 | Provisión inicial | Completado | — | `app:provision` idempotente: tenant, cuatro roles por tenant, owner, membresía y enlace de contraseña; `--no-invite`/`--resend-invite`; valida slug en modo single · 6 tests / 27 assertions (#47) |
| F1-11 | Deploy a staging | Pendiente | P1 | Recursos Cloud, variables, build/deploy, migraciones, dominio, scheduler |

## Fase 2 · Ingesta SRI

| ID | Tarea | Estado | Prioridad | Evidencia |
|---|---|---|---|---|
| F2-01 | Puerto `SriGateway` | Completado | — | `ce98289` · `sri-comprobantes-php` v0.1.2 · `SriAuthorizationGateway`, `SriRobotGateway`, adaptadores package/Lambda y fakes · 51 tests, 98.0% cobertura |
| F2-02 | Layout S3 del robot | Completado | — | `6abd600` · `SriRobotObjectPath`, disco `sri`, `SriRobotObjectStore` con SHA-256, layout `{ambiente}/{ruc}/{periodo}` · 61 tests, 97.7% cobertura |
| F2-03 | `SriSyncRun` y orquestación | Completado | — | `23c9ac6` · `SriSyncRun`, `SriSyncRunFile`, `StartSriSync`, estados/transiciones idempotentes y dispatch al robot · tests de feature y unit |
| F2-04 | Callback firmado | Completado | — | `POST /api/v1/webhooks/sri-robot` con HMAC-SHA256, ventana temporal, límite de tamaño y dedupe por `event_id`; `sri_robot_callbacks`, correlación por `callback_event_id` y `event_id`/`callback_url` en la invocación · tests de firma, expiración, duplicado, tamaño y transición · validado end-to-end desde `sri-txt-robot` en AWS: invocación real, callback `completed` con `202` y run en `downloading` |
| F2-05 | Reconciliación | Completado | — | `f5f82e9` · `periods`, `sri_sync_run_files`, `ReconcileSriRun(s)`, `sri:reconcile-runs`, scheduler `*/10`, validación de manifiesto/TXT/claves y transición a `parsing` · 12 tests F2-05 · suite 107 tests / 310 assertions |
| F2-06 | Parser XML | Completado | — | Paquete `sri-comprobantes-php` **v0.2.0** (`ComprobanteReader`: 01, 03, 04, 05, 06, 07, PR #5) consumido vía `SriInvoiceParser` + `PackageSriInvoiceParser` · XXE-safe, centavos, `totalsMismatch` sin corregir, `SriRetentionData` · parser local eliminado · `3053abd` añade la lectura del XML desde S3 y el sobre SOAP |
| F2-07 | Parser de manifiesto/TXT | Completado | — | `SriAccessKey` (verificador delegado a `AccessKeyGenerator` del paquete), `AccessKeyListParser`, `PeriodBatchBuilder`, `ParseSriRun` y `sri:parse-run` · run `completed`/`partial`/`failed` e idempotencia |
| F2-08 | Persistencia idempotente | Completado | — | `invoices`, `invoice_items`, `invoice_taxes`, `retention_lines` · `PersistSriInvoice` con `upsert` por `(tenant, company, access_key)` y huella `xml_sha256` · `ImportSriRun` + `sri:import-run` · fallos parciales sin tumbar el run · 15 tests nuevos |
| F2-09 | Progreso en vivo | Pendiente | **P0 / P1** | **F2-09a (P0):** endpoint de estado + polling cada 5 s, sin depender de Reverb. **F2-09b (P1):** eventos Reverb + canales privados. Cierra F2-09 cuando ambas partes estén verdes |
| F2-10 | Resiliencia | Completado | — | `ProcessSriRunJob` en cola `sri`, tenant-aware y único por run; callback y scheduler encolan la importación; parseo + batch de `ImportSriChunkJob` (25 claves), fallo por documento en `sri_sync_run_files` y cierre idempotente con `FinalizeSriRunJob` (`partial_import`/`import_failed`); reintentos 30/120/300 s; runs huérfanos resueltos por `ResolveStaleSriRun` (`robot_timeout`/`import_timeout`) con alerta por umbral · 10 tests nuevos en F2-10c (#33, #34) |
| F2-11 | Límites y cuotas | Completado | — | `StartSriSync` serializa por empresa (bloqueo de caché), rechaza runs solapados con `SriSyncAlreadyRunning`, reserva cuota por RUC con `RateLimiter` (`sri.sync.max_calls_per_minute`) y divide por `sri.sync.max_days_per_run` · los reintentos idempotentes no consumen cuota · 7 tests nuevos |
| F2-12 | Observabilidad | Completado | — | `SriRunMetrics` registra `sri.run.finished`/`partial`/`failed` con duración y volumen al cerrarse cada run; `sri:metrics` resume duración media/p95 y tasa de fallo por ventana · 7 tests nuevos (#35) |
| F2-13 | Carga manual de XML (`US-14`) | Pendiente | P1 | Upload validado (estructura, clave, totales), `source=manual_upload`, cuarentena y límites XXE |
| F2-14 | Cancelación de run | Pendiente | P2 | Endpoint idempotente → `cancelled`, detención de batches, UI en monitor |
| F2-15 | Runbook de runs huérfanos | Pendiente | P2 | Procedimiento de `sri:recover-run`, alerta por umbral y prueba de restauración |

## Fases 3–6 · Estado resumido

| Fase | Tareas | Estado | Prioridad actual |
|---|---|---|---|
| F3 · Motor fiscal + IA | F3-01…F3-09 | Pendiente | P1 (catálogos y reglas) / P2 (IA, riesgos, revisión) |
| F4 · Frontend | F4-01…F4-11 | Parcial: fundaciones, auth y layout mínimo | **P0** (design system, layout, bandeja, monitor) / P2 (resto) |
| F5 · Declaraciones | F5-01…F5-08 | Pendiente | P3 (después de F3) |
| F6 · Hardening y beta | F6-01…F6-08 | Pendiente | P3 (lanzamiento) |

## Registro de PRs recientes (2026-11-06 → 2026-11-09)

| PR | Contenido | Tarea |
|---|---|---|
| #33 | Importación por lotes con resultado por comprobante | F2-10b |
| #34 | Resolución de runs huérfanos y atascados | F2-10c |
| #35 | Métricas de run y `sri:metrics` | F2-12 |
| #36 | Argon2id, memberships y auth por sesión | F1-03a |
| #37 | Recuperación y verificación de correo | F1-03b |
| #38 | Roles por tenant y 2FA TOTP opcional | F1-03c |
| #39 | Invitaciones con roles | F1-03d |
| #40 | Enlace de recuperación y logout en UI | F4-03 (parcial) · mergeado 2026-11-09 |
| #45 | Actualización de prioridades y avance | Docs · mergeado 2026-11-09 |
| #46 | Policies de autorización por rol y tenant | F1-04 |
| #47 | Comando idempotente `app:provision` | F1-10 |

## Cómo actualizar

1. Cambiar el estado de la tarea a `Completado` y añadir commit + evidencia verificable.
2. Actualizar el avance de la fase en el resumen.
3. No marcar una tarea como completada si sus pruebas no están en verde o falta el criterio de salida de `docs/02-implementation-plan.md`.
