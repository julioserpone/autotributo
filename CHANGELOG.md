# Changelog

Todos los cambios relevantes de este proyecto se documentan aquí.

El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y el versionado [SemVer](https://semver.org/lang/es/).

## [Unreleased]

### Added

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
- F1-03 (slice b): recuperación y verificación de correo (forgot/reset con token, `MustVerifyEmail`, notificaciones, rutas firmadas y páginas `ForgotPassword`/`ResetPassword`). Invitaciones y 2FA quedan para siguientes slices.
- Comando `sri:reconcile-runs` y scheduler cada 10 minutos.
- Archivos de comunidad y gobierno: `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `SECURITY.md`, `SUPPORT.md`, `CHANGELOG.md`, `LICENSE`.
- Templates de issues, PR y `CODEOWNERS`.
- Dependabot para Composer, npm, GitHub Actions y Docker.
- Workflow de validación de títulos de PR con Conventional Commits.
- Reglas de protección de `main` en `.github/rulesets/main-protection.json` y script de aplicación.
- Hooks locales `pre-push` y `commit-msg`.

### Changed

- CI corregido: se eliminaron expresiones `hashFiles` no permitidas a nivel de job; se agregaron actionlint, cobertura y auditorías.
- El merge del repositorio queda configurado como squash-only, con borrado automático de la rama.
- Documentación de gobernanza alineada con PR obligatorio, sin pushes directos y sin aprobaciones obligatorias para el maintainer único.

### Fixed

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
