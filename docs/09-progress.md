# 09 — Estado de avance

> Tabla viva del proyecto. Se actualiza al cerrar cada tarea (una tarea = un commit/PR). La fuente de verdad del alcance sigue siendo `docs/02-implementation-plan.md`.

**Última actualización:** 2026-11-13

## Resumen por fase

| Fase | Estado | Avance | Último hito |
|---|---|---|---|
| F0 · Descubrimiento | Completado | 9/9 | Decisiones, ADRs y entorno local |
| F1 · Núcleo tenant-ready | Completado | 11/11 | F1-11 · Staging en AWS (EC2, RDS, DuckDNS, TLS, deploy, smoke tests) |
| F2 · Ingesta SRI | Completado | 12/12 + F2-13 | F2-13 · Carga manual de XML (`US-14`) |
| F3 · Motor fiscal + IA | Pendiente | 0/9 | — |
| F4 · Frontend | En curso | 0/11 + fundaciones/auth parciales | Login, reset, 2FA, invitación y layout mínimo (#36–#40) |
| F5 · Declaraciones | Pendiente | 0/8 | — |
| F6 · Hardening y beta | Pendiente | 0/8 | — |

> El recuento usa las tareas de `docs/02-implementation-plan.md`. Las slices de F1-03 y las tareas nuevas (F1-03e/f/g, F2-13/14/15, F4-03b/12) se detallan abajo y no alteran el denominador de la fase.
>
> **Siguiente etapa (Sprint A, tramo final → Sprint B).** Ambos documentos maestros deben coincidir: `docs/02` §3 (tareas) y §9/§17 (orden y prioridad) y esta tabla. Orden acordado: **F1-06 → F1-07 → F1-08 → F1-09 → F2-09 (a+b) → F1-03e → F1-11**. Justificación, diseño y pruebas obligatorias de cada tarea viven en `docs/02` §3 y no en un documento aparte.

## Fase 1 · Núcleo tenant-ready, identidad y empresas

| ID | Tarea | Estado | Prioridad | Evidencia |
|---|---|---|---|---|
| F1-01 | Esqueleto Laravel 13 + SPA | Completado | — | `042d1bf` · Pest, PHPStan 8, Pint, Vitest, E2E y 100% cobertura |
| F1-02 | Tenancy-ready | Completado | — | `bcf5896` · `stancl/tenancy` 3.10.1 sin DB por tenant · TenantContext, TenantScope, BelongsToTenant, TenantAware, caché/storage prefijados · 30 tests, 98.2% cobertura |
| F1-03 | Identidad | En curso (80 %) | P1 | Slice F1-03a (#36): `memberships`, Argon2id, login/logout/me por sesión, `LoginPage` y store de auth · Slice F1-03b (#37): recuperación y verificación de correo · Slice F1-03c (#38): Spatie Permission con teams por tenant, 2FA TOTP opcional · Slice F1-03d (#39): invitaciones con token, roles y aceptación · **Pendiente:** F1-03e sesiones revocables, F1-03f 2FA obligatorio para owner, F1-03g validaciones de invitación |
| F1-03e | Sesiones revocables y seguridad de cuenta (`US-05`) | Completado | P1 | `POST /api/v1/auth/password` (revoca otras sesiones), `GET /api/v1/auth/sessions`, `DELETE /api/v1/auth/sessions/{session}`, `GET /api/v1/auth/login-history`; 5 tests de feature |
| F1-03f | 2FA obligatorio para owner (`US-03`) | Completado | P1 | Login de owner sin 2FA devuelve `two_factor_setup_required` y bloquea endpoints de negocio; bloqueo tras 5 intentos por 15 min; registro de eventos en auditoría; UI de configuración (`/two-factor/setup`) con QR TOTP, clave manual y códigos de recuperación; tests de backend + store |
| F1-03g | Validaciones de invitación (`US-02`) | Pendiente | P2 | Email ya activo → 409; revocación de acceso invalida sesiones del tenant |
| F1-04 | Autorización | Completado | — | `Ability` + `RolePermissions` (matriz rol × acción) y policies `Company`, `SriSyncRun`, `Invoice`, `Membership`; defensa cross-tenant con `TenantContext` · 17 tests / 64 assertions (#46) |
| F1-05 | Empresas | Completado | — | CRUD `v1/companies`, `Ruc` (natural/jurídica/pública, módulo 10/11 y secuencial extendido), `TaxRegime`, perfil tributario y archivo con soft delete; policies de F1-04 y aislamiento cross-tenant · 33 tests / 124 assertions (#48) |
| F1-06 | Credenciales SRI | Completado | — | `sri_credentials` + `sri_credential_accesses` · `SriCredentialCipher` (sodium secretbox, `SRI_CREDENTIALS_KEY` + `key_version`) · `Store/Rotate/Delete/Read/MarkCredentialStatus` · endpoints `GET/PUT/DELETE /companies/{company}/credential` y `rotate` · `BuildSriRobotRequest` + `POST /sync-runs` (`Idempotency-Key`) y `GET /sync-runs/{run}` · archivar empresa borra la credencial · 24 tests nuevos |
| F1-07 | Auditoría base | Completado | — | `audit_logs` + `owen-it/laravel-auditing` ^14 con `AuditLog` propio (`tenant_id`, redacción de secretos) · trait `Auditable` en `Company`, `SriCredential`, `Membership`, `Invitation` · eventos `login`/`logout`/`login_failed` · `GET /audit-logs` (`audit.view`) filtrado por tenant · 6 tests nuevos |
| F1-08 | Reverb | Completado | — | `routes/channels.php` con `tenant.{tenantId}.company.{companyId}` y `tenant.{tenantId}.user.{userId}` (membresía + empresa del tenant) · `POST /api/v1/broadcasting/auth` · `GET /api/v1/realtime/status` sin secretos · `BroadcastServiceProvider` carga canales en `boot()` · 7 tests nuevos |
| F1-09 | Convenciones de API | Completado | **P0** | `ProblemDetails` + handlers (incl. 403), filtros declarativos `filter[...]` con compatibilidad hacia atrás, OpenAPI Scramble versionado y test de sincronización, Pint/PHPStan en verde · base del contrato para F2-09 y F4 |
| F1-10 | Provisión inicial | Completado | — | `app:provision` idempotente: tenant, cuatro roles por tenant, owner, membresía y enlace de contraseña; `--no-invite`/`--resend-invite`; valida slug en modo single · 6 tests / 27 assertions (#47) |
| F1-11 | Deploy a staging | Completado | P1 | ADR-0008 (EC2 única, RDS, DuckDNS/TLS) · `docker-compose.staging.yml` (app, queue, scheduler, reverb, Caddy) · target `staging` del Dockerfile, entrypoint con espera de BD y `optimize` · `scripts/staging/{deploy,provision-host,duckdns-update,ec2-control}.sh` + timer systemd · `.env.staging.example` · workflow manual `deploy-staging.yml` · runbook `docs/13-aws-staging-deployment.md`. Provisionado en AWS (us-east-2): EC2 `t3.micro`, RDS `db.t3.micro`, DuckDNS, Let's Encrypt. Smoke tests OK: /up, login (2FA required), containers healthy, scheduler, queue, TLS válido |

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
| F2-09 | Progreso en vivo | Completado | **P0 / P1** | **F2-09a (P0):** polling con `GET /sync-runs`, `/{run}` y `/{run}/files`. **F2-09b (P1):** evento `SriSyncProgressUpdated` en canal privado de la empresa. Cierra F2-09. |
| F2-10 | Resiliencia | Completado | — | `ProcessSriRunJob` en cola `sri`, tenant-aware y único por run; callback y scheduler encolan la importación; parseo + batch de `ImportSriChunkJob` (25 claves), fallo por documento en `sri_sync_run_files` y cierre idempotente con `FinalizeSriRunJob` (`partial_import`/`import_failed`); reintentos 30/120/300 s; runs huérfanos resueltos por `ResolveStaleSriRun` (`robot_timeout`/`import_timeout`) con alerta por umbral · 10 tests nuevos en F2-10c (#33, #34) |
| F2-11 | Límites y cuotas | Completado | — | `StartSriSync` serializa por empresa (bloqueo de caché), rechaza runs solapados con `SriSyncAlreadyRunning`, reserva cuota por RUC con `RateLimiter` (`sri.sync.max_calls_per_minute`) y divide por `sri.sync.max_days_per_run` · los reintentos idempotentes no consumen cuota · 7 tests nuevos |
| F2-12 | Observabilidad | Completado | — | `SriRunMetrics` registra `sri.run.finished`/`partial`/`failed` con duración y volumen al cerrarse cada run; `sri:metrics` resume duración media/p95 y tasa de fallo por ventana · 7 tests nuevos (#35) |
| F2-13 | Carga manual de XML (`US-14`) | Completado | P1 | `POST /api/v1/companies/{company}/invoices/manual` (multipart): valida XML, clave de acceso y totales; importa con `source=manual_upload`, marca `totals_mismatch` sin corregir, rechaza XXE y archivos > 2 MB; idempotente por `(tenant, company, access_key)` · 9 tests / 37 assertions (#59) |
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

## Registro de PRs recientes (2026-11-06 → 2026-11-13)

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
| #48 | CRUD de empresas y validador de RUC | F1-05 |
| #59 | Carga manual de XML autorizado y fix del script de staging | F2-13 |

## Cómo actualizar

1. Cambiar el estado de la tarea a `Completado` y añadir commit + evidencia verificable.
2. Actualizar el avance de la fase en el resumen.
3. No marcar una tarea como completada si sus pruebas no están en verde o falta el criterio de salida de `docs/02-implementation-plan.md`.
