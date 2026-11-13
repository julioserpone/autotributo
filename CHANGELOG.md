# Changelog

Todos los cambios relevantes de este proyecto se documentan aquí.

El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y el versionado [SemVer](https://semver.org/lang/es/).

## [Unreleased]

### Added

- F2-13: carga manual de XML autorizado (`US-14`) en `POST /api/v1/companies/{company}/invoices/manual`; valida estructura, clave de acceso y totales, importa con `source=manual_upload`, marca `totals_mismatch` sin corregir, rechaza XXE/archivos > 2 MB y es idempotente por `(tenant, company, access_key)`.
- F1-11: staging temporal en AWS (ADR-0008) con una EC2 `t3.micro` y Docker Compose (`app`, `queue`, `scheduler`, `reverb` y Caddy), RDS PostgreSQL `db.t3.micro` como base de datos, `database` para caché/sesiones/colas y DuckDNS con TLS automático; target `staging` del Dockerfile, entrypoint con espera de BD y `optimize`, `scripts/staging/{deploy,provision-host,duckdns-update,ec2-control}.sh`, workflow manual `deploy-staging.yml` y runbook `docs/13-aws-staging-deployment.md`. Provisionado en us-east-2: EC2 + RDS + DuckDNS + Let's Encrypt. Smoke tests OK.
- F1-08: canales privados de Reverb (`tenant.{tenantId}.company.{companyId}` y `tenant.{tenantId}.user.{userId}`) con autorización por membresía y empresa, `POST /api/v1/broadcasting/auth` y `GET /api/v1/realtime/status` (sin secretos).
- F1-09: convenciones de API: respuestas de error en `application/problem+json` (RFC 9457) para validación, autenticación, autorización, 404, throttling y errores de dominio; filtros declarativos `filter[...]` con compatibilidad hacia atrás en `companies` y `audit-logs`; OpenAPI generado con Scramble versionado en `docs/openapi.json` y validado en CI.
- F2-09a: monitor de sincronización por polling con `GET /api/v1/sync-runs`, `GET /api/v1/sync-runs/{run}` y `GET /api/v1/sync-runs/{run}/files`, incluyendo `progress` y `poll_after_ms`.
- F2-09b: evento `SriSyncProgressUpdated` emitido en el canal privado `tenant.{tenantId}.company.{companyId}` al guardar un run.
- F1-03e: seguridad de cuenta con cambio de contraseña (revoca otras sesiones), listado/revocación de sesiones activas e historial de inicios (`GET /api/v1/auth/login-history`).
- F1-03f: 2FA obligatorio para owner (token de alcance limitado sin 2FA, bloqueo tras 5 intentos por 15 min y registro del evento); UI de configuración con QR TOTP generado en el cliente, clave manual y códigos de recuperación.
- F1-07: auditoría base con `owen-it/laravel-auditing` sobre `audit_logs` (`AuditLog` propio con `tenant_id` y redacción de secretos), trait `Auditable` en `Company`, `SriCredential`, `Membership` e `Invitation`, eventos `login`/`logout`/`login_failed` y `GET /api/v1/audit-logs` (`audit.view`, filtrado por tenant).
- F1-06: credenciales del portal SRI cifradas con `SRI_CREDENTIALS_KEY` (sodium secretbox + `key_version`), acceso auditado en `sri_credential_accesses`, rotación y borrado al archivar la empresa; endpoints `GET/PUT/DELETE /api/v1/companies/{company}/credential` y `POST .../credential/rotate`.
- F1-06: `POST /api/v1/sync-runs` (con `Idempotency-Key`) arma la solicitud del robot con las credenciales persistidas y `GET /api/v1/sync-runs/{run}` expone el estado para el polling.
- Docs: Sincronización de `docs/02-implementation-plan.md` y `docs/09-progress.md` con el orden de la siguiente etapa (F1-06 → F1-07 → F1-08 → F1-09 → F2-09 → F1-03e → F1-11); se elimina el documento de etapa redundante `docs/13-next-stage-plan.md`.
- F2-03: orquestación de `SriSyncRun` con estados, transiciones idempotentes y dispatch al robot.
- F2-04: callback firmado del robot en `POST /api/v1/webhooks/sri-robot` (HMAC-SHA256 sobre `timestamp.cuerpo`, ventana ±300 s, límite de 256 KB y dedupe por `event_id`); `event_id`/`callback_url` en la invocación, `callback_event_id` en el run y transición a `downloading`/`failed`.
- F2-05: reconciliación de runs activos contra el manifiesto S3, validación de TXT/claves y transición a `parsing`.
- F2-06: parseo de comprobantes con `sri-comprobantes-php` v0.2.0 (`01`, `03`, `04`, `05`, `06`, `07`, `PR`), centavos sin `float`, detección de totales inconsistentes y retenciones.
- F2-07: interpretación del manifiesto y del TXT de claves (`SriAccessKey`, `AccessKeyListParser`, `PeriodBatchBuilder`, `ParseSriRun`).
- F2-08: persistencia idempotente de comprobantes (`invoices`, `invoice_items`, `invoice_taxes`, `retention_lines`) con `upsert` por `(tenant, company, access_key)` y huella `xml_sha256`.
- Lectura de XML desde el bucket S3 del robot con verificación de ambiente/RUC/período, `sri:recover-run` para runs sin callback y consulta al SRI solo dentro de la ventana de 45 días.
- F2-11: límites de sincronización con un run activo por empresa, bloqueo de caché, cuota configurable por RUC y ventana máxima por invocación.
- F2-10 (a): importación en cola con `ProcessSriRunJob` en la cola `sri`, tenant-aware, único por run y con reintentos; callback y scheduler dejan de importar de forma síncrona.
- F2-10 (b): importación por lotes con `ImportSriChunkJob` (`SRI_SYNC_CHUNK_SIZE`), resultado por comprobante en `sri_sync_run_files` (`error_code`, `import_outcome`, `source`) y cierre idempotente con `FinalizeSriRunJob`.
- F2-10 (c): resolución de runs huérfanos o atascados con `ResolveStaleSriRun` (`robot_timeout`/`import_timeout`), respeto del bloqueo de importación y alerta cuando una reconciliación cierra más de `SRI_SYNC_ORPHAN_ALERT_THRESHOLD` runs.
- F2-12: observabilidad con `SriRunMetrics` (línea estructurada `sri.run.finished`/`partial`/`failed` con duración y volumen) emitida por un observer al cerrarse cada run, y comando `sri:metrics` con duración media/p95, volumen y tasa de fallo por ventana.
- F1-03 (slice a): identidad base con Argon2id, tabla `memberships`, login/logout/me por sesión, `LoginPage` y store de auth.
- F1-03 (slice b): recuperación y verificación de correo (forgot/reset con token, `MustVerifyEmail`, notificaciones, rutas firmadas y páginas `ForgotPassword`/`ResetPassword`).
- F1-03 (slice c): Spatie Permission v8 con teams por tenant, 2FA TOTP opcional (enable/confirm/disable/challenge y códigos de recuperación) y página de desafío.
- F1-03 (slice d): invitaciones con token hasheado, envío de correo, aceptación que crea usuario/membresía/rol y rechazo de invitaciones expiradas. Sesiones revocables quedan para el siguiente slice.
- Comando `sri:reconcile-runs` y scheduler cada 10 minutos.
- Archivos de comunidad y gobierno: `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `SECURITY.md`, `SUPPORT.md`, `CHANGELOG.md`, `LICENSE`.
- Templates de issues, PR y `CODEOWNERS`.
- Dependabot para Composer, npm, GitHub Actions y Docker.
- Workflow de validación de títulos de PR con Conventional Commits.
- Reglas de protección de `main` en `.github/rulesets/main-protection.json` y script de aplicación.
- Hooks locales `pre-push` y `commit-msg`.
- Documentación de IA (`AGENTS.md`, `docs/ai/01-04`): alineada con Laravel 13/PHP 8.5, tenancy de BD única con `tenant_id`, rutas reales `app/Domain`, convención `handle()`, montos en centavos enteros y plantilla oficial de PR.

### Changed

- CI corregido: se eliminaron expresiones `hashFiles` no permitidas a nivel de job; se agregaron actionlint, cobertura y auditorías.
- El merge del repositorio queda configurado como squash-only, con borrado automático de la rama.
- Documentación de gobernanza alineada con PR obligatorio, sin pushes directos y sin aprobaciones obligatorias para el maintainer único.

### Fixed

- Script `scripts/staging/ec2-control.sh`: al detener staging se hace polling del estado de RDS en lugar de usar el waiter inexistente `db-instance-stopped` de AWS CLI 2.x.
- Validación del manifiesto S3 y de los archivos de claves antes de marcar un run como `parsing`.

### Security

- La contraseña SRI se cifra con `sodium_crypto_box_seal`; la clave privada permanece solo en la Lambda.
- `gitleaks` en CI con allowlist limitada a fixtures de test.

### Known limitations

- La protección de `main` no puede aplicarse en un repositorio privado con GitHub Free; requiere GitHub Pro o visibilidad pública. Ver `docs/github-branch-protection.md`.

## [0.1.0] - 2026-10-23

### Added

- Esqueleto Laravel 13 + SPA Vue 3 + TypeScript estricto.
- Tenancy single-tenant con aislamiento por `tenant_id`.
- Integración con `sri-comprobantes-php` y layout S3 real del robot.
- Gates de calidad: Pint, PHPStan/Larastan nivel 8, Pest, Vitest, Playwright, ESLint.
