# 09 — Estado de avance

> Tabla viva del proyecto. Se actualiza al cerrar cada tarea (una tarea = un commit/PR). La fuente de verdad del alcance sigue siendo `docs/02-implementation-plan.md`.

**Última actualización:** 2026-11-21

## Resumen por fase

| Fase | Estado | Avance | Último hito |
|---|---|---|---|
| F0 · Descubrimiento | Completado (con pendiente) | 9/9 | Decisiones, ADRs y entorno local · **falta el informe de `F0-05` en `docs/reports/`** |
| F1 · Núcleo tenant-ready | Completado | 11/11 | F1-11 · Staging efímero en AWS (EC2, Neon, DuckDNS, TLS, deploy, smoke tests) |
| F2 · Ingesta SRI | Completado | 12/12 + F2-13 | F2-13 · Carga manual de XML (`US-14`) |
| F3 · Motor fiscal + IA | En curso | 7/9 | F3-06 · Riesgos fiscales y hallazgos (#97) |
| F4 · Frontend | En curso | 5/11 + fundaciones/auth parciales | F4-07 · Revisión de clasificación con teclado (#98) |
| F5 · Declaraciones | Pendiente | 0/8 | — |
| F6 · Hardening y beta | Pendiente | 0/8 | — |

> El recuento usa las tareas de `docs/02-implementation-plan.md`. Las slices de F1-03 y F4-03 y las tareas nuevas (F1-03e/f/g, F2-13/14/15, F4-03a/b/c, F4-08b, F4-12, F4-13) se detallan abajo y no alteran el denominador de la fase.
>
> **Siguiente etapa (Etapa G, revisión 2026-11-21).** Ambos documentos maestros deben coincidir: `docs/02` §6 (tareas) y §9/§17 (orden y prioridad) y esta tabla. Orden acordado: **docs sync → F4-03a → F4-03b → F4-03c → F5-01 → F4-08 → F4-08b → F3-09**. F3-08 queda bloqueada por el dataset dorado y la firma del contador; F5-02 espera la firma de la convención del 104. Justificación, diseño y pruebas obligatorias viven en `docs/02` §6, §9 y §17, no en un documento aparte.
>
> **Estado de cierre de fase:** F1 y F2 están cerradas. F3 está funcionalmente completa pero **no puede declararse cerrada** sin `F3-08` ni la firma del contador (`docs/fiscal-signoff.md` sigue sin firmas). F4 no puede alcanzar M4 mientras no exista UI para empresas, credenciales SRI e invitaciones.

## Fase 1 · Núcleo tenant-ready, identidad y empresas

| ID | Tarea | Estado | Prioridad | Evidencia |
|---|---|---|---|---|
| F1-01 | Esqueleto Laravel 13 + SPA | Completado | — | `042d1bf` · Pest, PHPStan 8, Pint, Vitest, E2E y 100% cobertura |
| F1-02 | Tenancy-ready | Completado | — | `bcf5896` · `stancl/tenancy` 3.10.1 sin DB por tenant · TenantContext, TenantScope, BelongsToTenant, TenantAware, caché/storage prefijados · 30 tests, 98.2% cobertura |
| F1-03 | Identidad | Completado (slices a–f) | P1 | Slice F1-03a (#36): `memberships`, Argon2id, login/logout/me por sesión, `LoginPage` y store de auth · Slice F1-03b (#37): recuperación y verificación de correo · Slice F1-03c (#38): Spatie Permission con teams por tenant, 2FA TOTP opcional · Slice F1-03d (#39): invitaciones con token, roles y aceptación · Slice F1-03e: sesiones revocables · Slice F1-03f: 2FA obligatorio para owner. **Único pendiente del alcance:** F1-03g (validaciones de invitación) |
| F1-03e | Sesiones revocables y seguridad de cuenta (`US-05`) | Completado | P1 | `POST /api/v1/auth/password` (revoca otras sesiones), `GET /api/v1/auth/sessions`, `DELETE /api/v1/auth/sessions/{session}`, `GET /api/v1/auth/login-history`; 5 tests de feature |
| F1-03f | 2FA obligatorio para owner (`US-03`) | Completado | P1 | Login de owner sin 2FA devuelve `two_factor_setup_required` y bloquea endpoints de negocio; bloqueo tras 5 intentos por 15 min; registro de eventos en auditoría; UI de configuración (`/two-factor/setup`) con QR TOTP, clave manual y códigos de recuperación; tests de backend + store |
| F1-03g | Validaciones de invitación (`US-02`) | Pendiente | P2 | Email ya activo → 409; revocación de acceso invalida sesiones del tenant |
| F1-04 | Autorización | Completado | — | `Ability` + `RolePermissions` (matriz rol × acción) y policies `Company`, `SriSyncRun`, `FiscalDocument`, `Membership`; defensa cross-tenant con `TenantContext` · 17 tests / 64 assertions (#46) |
| F1-05 | Empresas | Completado | — | CRUD `v1/companies`, `Ruc` (natural/jurídica/pública, módulo 10/11 y secuencial extendido), `TaxRegime`, perfil tributario y archivo con soft delete; policies de F1-04 y aislamiento cross-tenant · 33 tests / 124 assertions (#48) |
| F1-06 | Credenciales SRI | Completado | — | `sri_credentials` + `sri_credential_accesses` · `SriCredentialCipher` (sodium secretbox, `SRI_CREDENTIALS_KEY` + `key_version`) · `Store/Rotate/Delete/Read/MarkCredentialStatus` · endpoints `GET/PUT/DELETE /companies/{company}/credential` y `rotate` · `BuildSriRobotRequest` + `POST /sync-runs` (`Idempotency-Key`) y `GET /sync-runs/{run}` · archivar empresa borra la credencial · 24 tests nuevos |
| F1-07 | Auditoría base | Completado | — | `audit_logs` + `owen-it/laravel-auditing` ^14 con `AuditLog` propio (`tenant_id`, redacción de secretos) · trait `Auditable` en `Company`, `SriCredential`, `Membership`, `Invitation` · eventos `login`/`logout`/`login_failed` · `GET /audit-logs` (`audit.view`) filtrado por tenant · 6 tests nuevos |
| F1-08 | Reverb | Completado | — | `routes/channels.php` con `tenant.{tenantId}.company.{companyId}` y `tenant.{tenantId}.user.{userId}` (membresía + empresa del tenant) · `POST /api/v1/broadcasting/auth` · `GET /api/v1/realtime/status` sin secretos · `BroadcastServiceProvider` carga canales en `boot()` · 7 tests nuevos |
| F1-09 | Convenciones de API | Completado | **P0** | `ProblemDetails` + handlers (incl. 403), filtros declarativos `filter[...]` con compatibilidad hacia atrás, OpenAPI Scramble versionado y test de sincronización, Pint/PHPStan en verde · base del contrato para F2-09 y F4 |
| F1-10 | Provisión inicial | Completado | — | `app:provision` idempotente: tenant, cuatro roles por tenant, owner, membresía y enlace de contraseña; `--no-invite`/`--resend-invite`; valida slug en modo single · 6 tests / 27 assertions (#47) |
| F1-11 | Deploy a staging | Completado | P1 | ADR-0008 (EC2 única y efímera, Neon, DuckDNS/TLS; enmienda 2: la imagen de GHCR es el toolchain y el código sale de `origin/main`) · `docker-compose.staging.yml` (app, queue, scheduler, reverb, Caddy) · Dockerfile con `toolchain`/`base`/`app`/`staging` (la instancia no compila PHP ni instala Node/Composer) · `scripts/staging/{deploy,build-app,image-refresh,provision-host,duckdns-update,ec2-control}.sh` + timer systemd · workflows `build-staging-image.yml` (solo con CI verde) y `deploy-staging.yml` · `.env.staging.example` · runbook `docs/13-aws-staging-deployment.md`. Provisionado en AWS (us-east-2): EC2 `t3.micro`, base Neon free tier, DuckDNS, Let's Encrypt. Smoke tests OK: /up, login (2FA required), containers healthy, scheduler, queue, TLS válido. Ciclo `create`/`destroy` validado: coste USD 0 en reposo, sin AMI ni snapshots |

## Fase 2 · Ingesta SRI

| ID | Tarea | Estado | Prioridad | Evidencia |
|---|---|---|---|---|
| F2-01 | Puerto `SriGateway` | Completado | — | `ce98289` · `sri-comprobantes-php` v0.1.2 · `SriAuthorizationGateway`, `SriRobotGateway`, adaptadores package/Lambda y fakes · 51 tests, 98.0% cobertura |
| F2-02 | Layout S3 del robot | Completado | — | `6abd600` · `SriRobotObjectPath`, disco `sri`, `SriRobotObjectStore` con SHA-256, layout `{ambiente}/{ruc}/{periodo}` · 61 tests, 97.7% cobertura |
| F2-03 | `SriSyncRun` y orquestación | Completado | — | `23c9ac6` · `SriSyncRun`, `SriSyncRunFile`, `StartSriSync`, estados/transiciones idempotentes y dispatch al robot · tests de feature y unit |
| F2-04 | Callback firmado | Completado | — | `POST /api/v1/webhooks/sri-robot` con HMAC-SHA256, ventana temporal, límite de tamaño y dedupe por `event_id`; `sri_robot_callbacks`, correlación por `callback_event_id` y `event_id`/`callback_url` en la invocación · tests de firma, expiración, duplicado, tamaño y transición · validado end-to-end desde `sri-txt-robot` en AWS: invocación real, callback `completed` con `202` y run en `downloading` |
| F2-05 | Reconciliación | Completado | — | `f5f82e9` · `periods`, `sri_sync_run_files`, `ReconcileSriRun(s)`, `sri:reconcile-runs`, scheduler `*/10`, validación de manifiesto/TXT/claves y transición a `parsing` · 12 tests F2-05 · suite 107 tests / 310 assertions |
| F2-06 | Parser XML | Completado | — | Paquete `sri-comprobantes-php` **v0.2.0** (`ComprobanteReader`: 01, 03, 04, 05, 06, 07, PR #5) consumido vía `SriFiscalDocumentParser` + `PackageSriFiscalDocumentParser` · XXE-safe, centavos, `totalsMismatch` sin corregir, `SriRetentionData` · parser local eliminado · `3053abd` añade la lectura del XML desde S3 y el sobre SOAP |
| F2-07 | Parser de manifiesto/TXT | Completado | — | `SriAccessKey` (verificador delegado a `AccessKeyGenerator` del paquete), `AccessKeyListParser`, `PeriodBatchBuilder`, `ParseSriRun` y `sri:parse-run` · run `completed`/`partial`/`failed` e idempotencia |
| F2-08 | Persistencia idempotente | Completado | — | `fiscal_documents`, `fiscal_document_items`, `fiscal_document_taxes`, `retention_lines` · `PersistSriFiscalDocument` con `upsert` por `(tenant, company, access_key)` y huella `xml_sha256` · `ImportSriRun` + `sri:import-run` · fallos parciales sin tumbar el run · 15 tests nuevos |
| F2-09 | Progreso en vivo | Completado | **P0 / P1** | **F2-09a (P0):** polling con `GET /sync-runs`, `/{run}` y `/{run}/files`. **F2-09b (P1):** evento `SriSyncProgressUpdated` en canal privado de la empresa. Validado en vivo con Reverb + queue (evento recibido por WebSocket con `progress` y `poll_after_ms`); corregido el host interno de Reverb en Docker y la resolución de empresa sin contexto de tenant en el webhook. 12 tests nuevos de monitor y evento (`SyncRunMonitorTest`, `SriSyncProgressUpdatedTest`). |
| F2-10 | Resiliencia | Completado | — | `ProcessSriRunJob` en cola `sri`, tenant-aware y único por run; callback y scheduler encolan la importación; parseo + batch de `ImportSriChunkJob` (25 claves), fallo por documento en `sri_sync_run_files` y cierre idempotente con `FinalizeSriRunJob` (`partial_import`/`import_failed`); reintentos 30/120/300 s; runs huérfanos resueltos por `ResolveStaleSriRun` (`robot_timeout`/`import_timeout`) con alerta por umbral · 10 tests nuevos en F2-10c (#33, #34) |
| F2-11 | Límites y cuotas | Completado | — | `StartSriSync` serializa por empresa (bloqueo de caché), rechaza runs solapados con `SriSyncAlreadyRunning`, reserva cuota por RUC con `RateLimiter` (`sri.sync.max_calls_per_minute`) y divide por `sri.sync.max_days_per_run` · los reintentos idempotentes no consumen cuota · 7 tests nuevos |
| F2-12 | Observabilidad | Completado | — | `SriRunMetrics` registra `sri.run.finished`/`partial`/`failed` con duración y volumen al cerrarse cada run; `sri:metrics` resume duración media/p95 y tasa de fallo por ventana · 7 tests nuevos (#35) |
| F2-13 | Carga manual de XML (`US-14`) | Completado | P1 | `POST /api/v1/companies/{company}/fiscal_documents/manual` (multipart): valida XML, clave de acceso y totales; importa con `source=manual_upload`, marca `totals_mismatch` sin corregir, rechaza XXE y archivos > 2 MB; idempotente por `(tenant, company, access_key)` · 9 tests / 37 assertions (#59) |
| F2-14 | Cancelación de run | Pendiente | P2 | Endpoint idempotente → `cancelled`, detención de batches, UI en monitor |
| F2-15 | Runbook de runs huérfanos | Pendiente | P2 | Procedimiento de `sri:recover-run`, alerta por umbral y prueba de restauración |

## Fase 3 · Motor fiscal + IA

| ID | Tarea | Estado | Prioridad | Evidencia |
|---|---|---|---|---|
| F3-01 | Catálogos versionados | Completado | P1 | `tax_rates`, `retention_rates`, `form_lines` y `personal_expense_caps` con `valid_from/valid_to`, `source`, `published_at` y `checksum`; `SyncFiscalCatalogs` idempotente, seeder, integración con `app:provision` y comando `catalogs:validate`; IVA 0/5/12/14/15 %, retenciones IR/IVA 2026 y topes 2026 (CBF enero = USD 821,80) · 7 tests / 35 assertions. **Pendiente de firma del contador** (`docs/fiscal-signoff.md`) (#60) |
| F3-02 | Motor de reglas | Completado | P1 | `expense_categories` (9 categorías), `classification_rules` (tenant/global, soft delete) y `classifications`; `ClassificationRuleMatcher` (`contains`, `starts_with`, `exact`, `regex`, `provider_ruc`, `item_code`), precedencia por `priority` con desempate tenant > global, `ClassificationRuleRepository` con caché versionada e invalidación al guardar/borrar; dry-run y detección de conflictos con `409 classification-rule-conflict`; `ClassifyFiscalDocumentItems` integrado en `PersistSriFiscalDocument` sin bloquear la importación; endpoints `classification-rules` (CRUD + dry-run) y `expense-categories`; provisión y seeder idempotentes · 37 tests / 154 assertions. `match_type=item_code` documentado en `docs/03-data-model.md`. **Pendiente firma de deducibilidad** (`docs/fiscal-signoff.md`) (#62) |
| F3-03 | Clasificador IA | Completado | P1 | `Classifier` + DTOs (`ClassificationRequest`, `ClassificationSuggestion`, `ClassifierResult`), prompt versionado `v1` en `resources/prompts/classify-item.v1.md`, `ClassifierOutputValidator` estricto (claves exactas, categoría del catálogo, confianza 0..1, `reason` ≤ 300, rechazo de markdown/JSON truncado), `OpenAiClassifier` (JSON Schema estricto) y `AnthropicClassifier`, `FakeClassifier` determinista, `ResilientClassifier` con un reintento solo ante salida inválida y `ClassifierCircuitBreaker` por tenant; `ClassifyFiscalDocumentItems` usa la IA solo si ninguna regla coincide y aplica `LLM_CONFIDENCE_THRESHOLD`: `auto`/`suggested` o `review`/`needs_review`, con degradación a `review` sin clasificación persistida ante timeout/429/5xx/salida inválida/circuito abierto/error inesperado · 39 tests / 131 assertions. Validado contra PostgreSQL local (smoke transaccional revertido: `auto`, `llm`, confianza 0,95, prompt `v1`) y con un mock HTTP local real para ambos adaptadores. Persistencia de `llm_calls`, caché por hash y presupuesto quedan para F3-04 (#63) |
| F3-04 | Gobierno de IA | Completado | P1 | `llm_calls` (tenant/company, `purpose`, `provider`, `model`, `prompt_version`, `input_hash` SHA-256, tokens, `cost_micros`, `latency_ms`, `status`, `error_code`, `created_at`) enlazado desde `classifications.llm_call_id`; `GovernedClassifier` decide caché → presupuesto → proveedor; `LlmCache` reutiliza la última clasificación exitosa con la misma huella normalizada (descripción sin acentos, rubro, catálogo, modelo y prompt) y persiste `method=cache` sin coste; `LlmBudget` suma el mes en curso y corta antes de llamar con `budget_exceeded`, dejando el ítem en `review`; aviso al 80 % una vez al mes; `LlmCostCalculator` calcula en micros con enteros desde `config/ai.php`; `ClassifierUnavailable` e `InvalidClassifierOutput` exponen `errorCode()` para la bitácora. Groq/Gemini se prueban por configuración (`LLM_DRIVER=openai` + `OPENAI_BASE_URL`) · 15 tests / 70 assertions. Smoke real contra PostgreSQL (transacción revertida): `llm` con 1.200/300 tokens, 960 micros y 37 ms; segunda descripción idéntica `cache` con el mismo `llm_call_id` y una sola petición; presupuesto agotado `review` + `budget_exceeded` sin llamada (#64) |
| F3-05 | Revisión humana | Completado | P1 | `GET /api/v1/classifications` (cola paginada que prioriza `needs_review` y filtra por `status`, `method`, `company_id`, `reviewed` y texto), `PATCH /api/v1/classifications/{classification}` (`decision=accept|reject`, categoría opcional para corregir) y `POST /api/v1/classifications/bulk-review` (máximo 100 ids, resultado parcial con `{updated, failed:[{id, reason}]}`); `ReviewClassification` acepta y deja el ítem `manual` con la categoría confirmada o lo rechaza devolviéndolo a `pending` sin categoría, siempre en transacción con bloqueo y conservando el origen (`method`, `rule_id`, `llm_call_id`); `Classification` pasa a ser auditable (`audit_logs` con `old_values`/`new_values`); `ClassificationPolicy` con `fiscal_documents.view` para leer y `fiscal_documents.review` para decidir (owner, accountant y assistant); revisar dos veces es válido y queda auditado · 12 tests / 71 assertions. OpenAPI regenerado (#69) |
| F3-06 | Riesgos fiscales | Completado | P2 | `fiscal_findings` y `ghost_companies`; `DetectFiscalRisks` con `TOTALS_MISMATCH`, `INVALID_RUC`, `GHOST_COMPANY` y `DUPLICATE_SEQUENTIAL`; auto-resolución para riesgos técnicos objetivos; endpoints `GET/PATCH /api/v1/fiscal-findings` y `POST /api/v1/companies/{company}/fiscal-findings/detect`; integración best-effort en `FinalizeSriRun`; comando `fiscal:detect-risks` · 14 tests / 30 assertions. Pendiente firma del contador para severidades y códigos (`docs/fiscal-signoff.md`). |
| F3-07 | Proporcionalidad IVA | Completado | P1 | `App\Domain\Shared\Values\Money` (value object inmutable sobre `brick/money` ^0.15: `cents()`, `ofDecimal()` con HALF_UP, `toCents()`, `decimal()`, `plus`/`minus`/`multipliedBy`, sin `float`) y `App\Domain\Fiscal\Services\Proportionality` (`factor()` = ventas 0 % / total con 6 decimales y `RoundingMode::HalfUp`; `complement()` calculado como `1 − factor` para que ambas caras sumen `1.000000` exacto; `apply()` del factor a un importe con redondeo a centavos; período sin ventas → `0.000000`; ventas negativas y factor fuera de [0,1] → `InvalidArgumentException`) · 33 tests / 66 assertions. Cierra el hueco del ADR-0002 (enmienda 2026-11-15): `brick/money` no estaba instalado y no existía `Money`. **Convención del 104 pendiente de firma del contador** (`docs/fiscal-signoff.md`) (#65) |
| F3-08 | Evaluación offline | Pendiente | P1 | **Bloqueada por datos:** exige un dataset dorado de ~300 descripciones reales anonimizadas que no existe en el repositorio (`docs/reports/` solo tiene el README y no hay informe de `F0-05`). Entregables: `ai:evaluate` (grupo `ai-eval`), reporte de accuracy/precisión/coste en `docs/reports/ai-eval-YYYY-MM-DD.md` y ejecución semanal. Sin ella, el criterio de salida M3 (≥ 70 % automático, accuracy ≥ 92 %, coste < USD 0,002/ítem) **no es demostrable** |
| F3-09 | Administración de catálogos | Pendiente | P2 | Endpoints de solo lectura (`tax_rates`, `retention_rates`, `form_lines`, `personal_expense_caps`) y comando de importación desde CSV oficial. Hoy solo existen `catalogs:validate` y el seeder; no hay API para que la UI muestre vigencias |

## Fase 4 · Frontend

| ID | Tarea | Estado | Prioridad | Evidencia |
|---|---|---|---|---|
| F4-01 | Fundaciones | Parcial | P1 | Vite, Vue 3.5 + TypeScript estricto, Tailwind 4, router, Pinia y cliente HTTP con CSRF/errores. El entorno de Vitest con stubs de layout existe. **Pendiente:** TanStack Query, i18n y endurecer `lib/api.ts` (hoy descarta los `errors` de `problem+json` y no soporta respuestas `204`) |
| F4-02 | Design system | Completado | **P0** | Tokens semánticos dark-first en `resources/css/app.css`, tipografía, `prefers-reduced-motion` y foco visible; componentes base `AppField`, `AppButton`, `AppInput`, `AppSelect`, `AppCombobox`, `AppDateRange`, `AppTable`, `AppBadge`, `AppModal`, `AppToastHost` y `AppDrawer` · 50 tests · `docs/14-design-system.md`. **Superado por F4-04:** los wrappers propios se sustituyeron por Nuxt UI (ADR-0009). (#70) |
| F4-03 | Auth y primer acceso | En curso (slices a–c) | **P0** | Hecho: login, 2FA (setup y challenge), recuperación y restablecimiento de contraseña, cierre de sesión. **En curso (2026-11-21, rama `feat/F4-03-companies-ui`): F4-03a slice 1 — cliente HTTP de la SPA sobre `problem+json` (`ApiError` con `errors`/`fieldError`, `deleteJson` para `204`, cuerpos vacíos tolerados) y store `company` con `createCompany`/`updateCompany`/`archiveCompany`/`fetchCompany` (orden por razón social, selección activa y tests). **Pendiente:** F4-03a slices 2 y 3 (página de empresas, formulario y archivo con confirmación de RUC; ruta, navegación y estado vacío del panel), F4-03b (credencial SRI en la UI, = F4-13) y F4-03c (invitaciones en la UI) |
| F4-04 | Layout | Completado | **P0** | Template Dashboard de Nuxt UI 4.13 (`UDashboardGroup`, `UDashboardSidebar` colapsable/redimensionable, `UDashboardPanel/Navbar/Toolbar/Search`) y tema Mint: `primary: teal`, `neutral: olive`, radio `0.75rem`, botones/inputs píldora, Nunito Variable (500/600/700/800) y JetBrains Mono. `AppShell`, `AppPage`, `AppSidebar`, `AppWorkspaceMenu`, `AppUserMenu`, `AuthLayout` y `lib/navigation.ts` (nav + ⌘K); selector de tema en el menú de usuario. Páginas migradas a `bg-default`/`bg-elevated`/`border-default`/`text-highlighted`/`text-muted`/`text-dimmed`. `useTheme` conserva `colorMode: false`. ADR-0009 enmendado y `docs/14-design-system.md` reescrito · 43 tests, `vue-tsc` y ESLint en verde · verificado en navegador (login, panel, bandeja, monitor y paleta ⌘K; claro y oscuro). (#90) |
| F4-05 | Bandeja de comprobantes | Completado | **P0** | `GET /api/v1/fiscal_documents` (cursor + filtros por empresa, estado, tipo, emisor, fechas), `GET /api/v1/fiscal_documents/{fiscal_document}` con líneas/impuestos/retenciones; `FiscalDocumentInboxPage` con filtros, paginación «Cargar más», drawer de detalle y selección múltiple. Migrado a `FiscalDocument` en PR #91. (#72) |
| F4-06 | Monitor de sincronización | Completado | **P0** | `SyncMonitorPage` con formulario en una fila (empresa, tipos de documento como multiselección, desde/hasta), ayuda contextual para forzar re-descarga, progreso en vivo con polling (`poll_after_ms`) + archivos con errores, totales, historial y detalle de run. Store `sync` con `startSync` (Idempotency-Key), `loadRun`, `startPollingIfNeeded`. Refinamientos UI (#74, #75), progreso de `downloading` sin contar XML `missing`/`invalid`, historial estable durante refresco. (#73–#75) |
| F4-07 | Revisión de clasificación | Completado | **P1** | `ClassificationReviewPage` con cola que prioriza `needs_review`, filtros, confianza y método visuales, atajos `j`/`k`/`Enter`/`a`/`r`/`espacio`, aceptación y rechazo en bloque con resultado parcial y creación de regla desde un ítem; store `classifications` y componentes en `components/classifications/`. Ruta `/classifications` y entrada en la navegación. (#98) |
| F4-08 | Dashboards | Pendiente | **P1** | **Depende de F5-01:** hoy `DashboardPage` solo muestra ceros o datos de `?preview=1` (`dashboardPreview.ts`). Entregables: IVA ventas vs. compras por mes, top proveedores, topes de gastos personales y alertas reales desde `fiscal_findings` |
| F4-08b | Bandeja de hallazgos | Pendiente | **P1** | Nueva tarea derivada de F3-06: el contrato `GET/PATCH /api/v1/fiscal-findings` ya existe, pero no hay UI para reconocer, resolver o descartar hallazgos ni para lanzar la detección manual |
| F4-09 | Vista 104 (estructura) | Pendiente | P2 | Casilleros, totales, drill-down y estados de borrador; los datos reales llegan en F5-02/F5-06 |
| F4-10 | Calidad de UI | Pendiente | P2 | Estados vacíos/carga/error, responsive, foco visible, contraste AA y `prefers-reduced-motion` en todas las pantallas |
| F4-11 | Pruebas frontend | Pendiente | P2 | MSW, `axe` y Playwright para los 5 flujos críticos; hoy solo hay Vitest parcial y Playwright configurado |
| F4-12 | Indicador de Reverb | Pendiente | P2 | Nueva tarea derivada de F1-08/F2-09b: estado de conexión y reconexión del WebSocket en el monitor (hoy el polling cubre el caso) |
| F4-13 | Credenciales SRI en la UI | Pendiente | **P0** | Nueva tarea derivada de F1-06: sin formulario de credencial (alta, rotación, borrado y estado) no se puede iniciar una sincronización real desde la app. Se ejecuta como F4-03b |

## Fases 3–6 · Estado resumido

| Fase | Tareas | Estado | Prioridad actual |
|---|---|---|---|
| F3 · Motor fiscal + IA | F3-01…F3-09 | En curso (7/9) | P1 (F3-08 evaluación, bloqueada por dataset) / P2 (F3-09 catálogos) |
| F4 · Frontend | F4-01…F4-11 (+ F4-08b, F4-12, F4-13) | En curso (5/11 + fundaciones/auth parciales) | **P0** (F4-03a/b/c) / **P1** (F4-08, F4-08b) / P2 (F4-09/10/11/12) |
| F5 · Declaraciones | F5-01…F5-08 | Pendiente | **P1** (F5-01 agregados) / P2 (resto, tras firma del 104) |
| F6 · Hardening y beta | F6-01…F6-08 | Pendiente | P3 (lanzamiento) |

## Registro de PRs recientes (2026-11-06 → 2026-11-21)

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
| #60 | Catálogos fiscales versionados y comando `catalogs:validate` | F3-01 |
| #61 | Fix del progreso en vivo de Reverb en Docker | F2-09 |
| #62 | Motor de reglas de clasificación | F3-02 |
| #63 | Clasificador IA resiliente y degradación a revisión | F3-03 |
| #64 | Gobierno de IA: caché, `llm_calls` y presupuesto | F3-04 |
| #65 | Value object `Money` y proporcionalidad de IVA | F3-07 |
| #69 | Revisión humana de clasificaciones | F3-05 |
| #70 | Design system dark-first y componentes base | F4-02 |
| #71 | Layout Laravel Cloud sobre Nuxt UI, tema claro/oscuro y bandeja con vista previa | F4-04 |
| #72 | Bandeja de comprobantes: endpoint cursor + filtros, drawer de detalle y selección | F4-05 |
| #73 | Monitor de sincronización: progreso en vivo, polling y errores por archivo | F4-06 |
| #74 | Refinamiento del monitor SRI y textos de dominio | F4-06 |
| #75 | Alineación de rutas de componentes del monitor | F4-06 |
| #76 | Identidad de ejecución del robot, importación manual y sondeo sin callback | F2-10d |
| #77 | Sondeo automático de S3 sin callback y reconciliación cada 5 min | F2-10d |
| #78 | Actualización de progreso y changelog para identidad de ejecución del robot | Docs · F2-10d |
| #79 | Progreso, alertas transitorias e historial estable del monitor SRI | F2-09/F4-06 |
| #80 | Instancias efímeras con Neon e imagen base de PHP pre-construida | F1-11/Staging |
| #81 | Mover `ARG BASE_IMAGE` al alcance global | Staging |
| #82 | Documentar la imagen base de PHP en GHCR | Staging |
| #83 | Imagen completa de la app en GHCR (opción E) | Staging |
| #84 | No copiar archivos generados en el stage assets | Staging |
| #85 | Documentar la imagen completa de la app | Staging |
| #86 | Flujo por defecto sin `APP_IMAGE` | Staging |
| #87 | Corregir sintaxis de `deploy.sh` (comillas en `APP_IMAGE`) | Staging |
| #88 | Usar la imagen de GHCR como toolchain y tomar código de git | Staging |
| #89 | Tolerar filas XML no registradas y permitir sondeo completo del robot | F2-10d |
| #90 | Adoptar layout Dashboard de Nuxt UI y tema Mint (F4-04) | F4-04 |
| #91 | Renombrar `Invoice` a `FiscalDocument` (refactor, etapa 2) | FiscalDocument refactor |
| #92 | Compilar `phpredis` desde GitHub en lugar de pecl.php.net | Staging |
| #93 | UI estilo dashboard y flag `--recreate-db` en `ec2-control` | Staging |
| #94 | UI de dashboard en destroy y fallo rápido por timeout SSH | Staging |
| #96 | Mejorar UI de dashboard y fallo rápido SSH en destroy | Staging |
| #97 | Detectar riesgos fiscales y exponer hallazgos | F3-06 |
| #98 | Página de revisión de clasificación con navegación por teclado | F4-07 |

## Pendientes transversales (2026-11-21)

| Pendiente | Impacto | Siguiente paso |
|---|---|---|
| Firma del contador (`docs/fiscal-signoff.md` vacío) | Bloquea el cierre formal de F3 y el arranque de F5-02 | Enviar catálogos, categorías, proporcionalidad, códigos de riesgo y escenarios del 104 |
| Dataset dorado de F3-08 | M3 no es demostrable (accuracy/coste) | Extraer ~300 descripciones reales anonimizadas y congelar la categoría correcta |
| Informe de F0-05 en `docs/reports/` | F0 figura 9/9 sin su entregable | Versionar el informe base de evaluación IA |
| F1-03g validaciones de invitación | 409 por email activo y revocación de sesiones al retirar acceso | PR pequeño tras F4-03c |
| F2-14 cancelación de run | Archivar empresa no detiene un run en curso (`ArchiveCompany` lo delega) | PR pequeño, P2 |
| F2-15 runbook de runs huérfanos | Procedimiento operativo de `sri:recover-run` | Documento + prueba de restauración |
| F4-01 TanStack Query / i18n / cliente HTTP | `lib/api.ts` no expone `errors` de `problem+json` ni soporta `204` | Se corrige en F4-03a (slice 1); TanStack/i18n quedan para después |
| Catálogo CIIU (actividades económicas) | Tabla `economic_activity_codes` con el catálogo completo (6378 códigos, columna `is_selectable` para los 3125 nodos hoja) + seeder (CSV `database/seeders/data/ciiu.csv`) + endpoint `GET /api/v1/economic-activity-codes` (por defecto solo seleccionables; `?all=1` devuelve todo; búsqueda case-insensitive) en rama `feat/ciiu-activity-codes`. El combobox en `CompanyFormModal` queda para una rama dependiente de `feat/F4-03-companies-page` (#101) | Backend listo; frontend pendiente de la fusión de #101 |

## Cómo actualizar

1. Cambiar el estado de la tarea a `Completado` y añadir commit + evidencia verificable.
2. Actualizar el avance de la fase en el resumen.
3. No marcar una tarea como completada si sus pruebas no están en verde o falta el criterio de salida de `docs/02-implementation-plan.md`.
