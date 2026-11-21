# 02 — Plan de implementación

> **Cómo usar este documento:** cada fase tiene objetivo, tareas con ID (`F1-03`), criterios de aceptación verificables y pruebas obligatorias. Una fase no se cierra si sus pruebas no están en verde y el criterio de salida no está demostrado en *staging*. Los agentes de IA deben ejecutar una tarea por PR y no avanzar de fase sin aprobación humana.

## 0. Supuestos

| Supuesto | Valor |
|---|---|
| Equipo | 1 backend senior + 1 frontend (a tiempo parcial) + contador asesor (2–4 h/semana) |
| Duración MVP | **16–18 semanas** con 2 personas; **20–23 semanas** con 1 persona |
| Modalidad | Sprints de 2 semanas, trunk-based, PRs pequeños |
| Entorno | Docker local; staging temporal en AWS (ADR-0008) y production en Laravel Cloud |
| Volumen objetivo | MVP: 1 estudio con N contribuyentes; diseño preparado para 100 tenants, 1.000 usuarios y 1 M comprobantes/año |
| Dependencia externa crítica | Acceso al repo/despliegue de `julioserpone/sri-txt-robot` y a una cuenta AWS |
| Zona horaria | Persistencia UTC, presentación `America/Guayaquil` |
| Moneda | USD (Ecuador dollarizado); se guarda `currency` por si cambia el alcance |

## 1. Mapa de fases e hitos

| Fase | Semanas | Hito | Demostrable |
|---|---|---|---|
| F0 Descubrimiento | 1 | **M0** Decisiones y contrato | ADRs + spike Lambda + baseline IA + catálogo fiscal v0 |
| F1 Núcleo tenant-ready | 2 | **M1** Login y empresas | Login → invitación → registrar empresa → 2FA (tenant por defecto) |
| F2 Ingesta SRI | 3 | **M2** Descarga real | Sincronizar un mes y ver comprobantes con detalle |
| F3 Motor fiscal + IA | 3 | **M3** Clasificación y riesgos | Ítems clasificados, alertas y revisión humana |
| F4 Frontend | 3 | **M4** Producto usable | Flujo completo desde la UI, en tiempo real |
| F5 Declaraciones | 3 | **M5** 104/103/102 | Borrador con drill-down + PDF/Excel validado por contador |
| F6 Hardening y beta | 2 | **M6** Lanzamiento | Beta con 3–5 estudios + runbooks + auditoría |

Dependencias: F3 puede solaparse con F2 (una vez existen comprobantes de fixture); F4 arranca en paralelo desde la mitad de F2 usando mocks/OpenAPI.

## 2. Fase 0 — Descubrimiento y decisiones (1 semana)

**Objetivo:** eliminar las incógnitas que pueden invalidar la arquitectura antes de escribir producto.

| ID | Tarea | Est. | Detalle |
|---|---|---|---|
| F0-01 | Bootstrap del repositorio | 0,5 d | `git init`, `.gitignore`, `.editorconfig`, Docker Compose, Makefile, CI base, PR template |
| F0-02 | ADRs 0001–0006 | 0,5 d | Tenancy single-tenant/tenant-ready, dinero/redondeo, colas, empaquetado SPA, catálogos fiscales. Revisión y firma |
| F0-03 | Spike de plataforma en staging Cloud | 1 d | Crear app + Postgres serverless + Valkey + queue + S3; medir arranque en frío y pooling; validar el flujo de storage con un bucket de staging. **No** se prueba `CREATE DATABASE`/`CREATE SCHEMA`: se descartó en ADR-0006 |
| F0-04 | Spike del robot SRI | 1,5 d | Invocar la Lambda con un run real (o sandbox) usando destino S3; medir duración, tamaño de paquete, formato de TXT/XML y errores; documentar contrato definitivo |
| F0-05 | Baseline de clasificación IA | 1 d | 300 descripciones reales anonimizadas; medir accuracy por categoría, coste y latencia con 2 modelos; congelar prompt v1 y umbral de confianza |
| F0-06 | Catálogo fiscal v0 | 1 d | Tarifas IVA con vigencia (0 %, 5 %, 12 % histórico, 15 %), casilleros 104/103, tasas de retención, topes de gastos personales. **Validado por contador** |
| F0-07 | Revisión legal y de privacidad | 0,5 d | Base legal para tratar credenciales del contribuyente, aviso de privacidad, retención, derechos ARCO (LOPDP) |
| F0-08 | Contrato de API v0 + wireframes | 1 d | OpenAPI de auth/empresas/sync/facturas + 4 pantallas clave (login, bandeja, monitor, 104) |
| F0-09 | Amenazas y modelo de datos | 0,5 d | STRIDE rápido, revisión del modelo de datos, política de backups |

**Pruebas obligatorias de la fase**
- `make up` funciona en una máquina limpia y `make ps` muestra todos los servicios *healthy*.
- Script de spike (no productivo) que invoca la Lambda y descarga el paquete desde S3, con resultado reproducible.
- Informe de evaluación IA versionado en `docs/reports/` con matriz de confusión y coste por ítem.

**Criterio de salida (M0)**
- ADRs firmados y aprobados por el responsable técnico.
- Contrato Lambda ↔ Laravel congelado (request/response/manifest/callback) y probado end-to-end una vez.
- Decisión de tenancy cerrada: **single-tenant con esquema tenant-ready** (ADR-0006) y una sola base de datos por entorno; no se aprovisionan bases por cliente.
- Catálogo fiscal v0 aprobado por el contador asesor.

## 3. Fase 1 — Núcleo tenant-ready, identidad y empresas (2 semanas)

**Objetivo:** un estudio provisionado por comando puede iniciar sesión, invitar colegas, registrar contribuyentes y operar con el tenant por defecto, con el aislamiento ya cableado para el futuro modo multi.

| ID | Tarea | Est. | Detalle |
|---|---|---|---|
| F1-01 | Esqueleto Laravel 13 + SPA | 1 d | `laravel new`, API + Vue/TS strict, Pint, PHPStan nivel 8, Pest, `.env.example`, `README` de arranque |
| F1-02 | Tenancy-ready | 1,5 d | `stancl/tenancy` sin `DatabaseTenancyBootstrapper`; `Tenant`, `TenantContext`, `TenantScope`, `BelongsToTenant`, `TenantAware` para jobs, prefijos de caché/storage, `TENANCY_MODE=single` con tenant por defecto. `Domain`/`ResolveTenant` se dejan reservados para multi (ADR-0006) |
| F1-03 | Identidad | 2 d | `users` global + `memberships` por tenant, invitaciones, reset, verificación de email, 2FA TOTP, Argon2id, sesiones revocables. **Completado 2026-11-20 (slices a–f, PRs #36–#39 y F1-03e/f):** login/logout/me por sesión, recuperación y verificación de correo, roles por tenant, invitaciones con token, sesiones revocables y 2FA obligatorio para owner. **Pendiente:** F1-03g (validaciones de invitación) |
| F1-03e | Sesiones revocables y seguridad de cuenta | 1,5 d | Cambio de contraseña con cierre del resto de sesiones, listado/revocación de sesiones activas e historial de inicios (`US-05`). **Nueva tarea derivada del backlog** |
| F1-03f | 2FA obligatorio para owner | 1 d | Token de alcance limitado si el owner no tiene 2FA confirmado, bloqueo tras 5 intentos por 15 min y registro del evento (`US-03`). **Nueva tarea derivada del backlog** |
| F1-03g | Validaciones de invitación | 0,5 d | No invitar un email ya activo (409); revocar acceso invalida las sesiones del usuario en el tenant (`US-02`). **Nueva tarea derivada del backlog** |
| F1-04 | Autorización | 1 d | Roles `owner/accountant/assistant/viewer` (spatie teams), policies por recurso, scoping por empresa. **Se ejecuta antes de F1-05/F1-06.** Completado 2026-11-09 (PR #46) |
| F1-05 | Empresas | 1,5 d | CRUD, validador de RUC, perfil tributario (régimen, obligado a contabilidad, agente de retención), actividad económica, dirección. Completado 2026-11-09 (PR #48) |
| F1-06 | Credenciales SRI | 1 d | `sri_credentials` + `sri_credential_accesses`; cifrado AES-256-GCM (sodium secretbox) con `SRI_CREDENTIALS_KEY` y `key_version`; endpoints `GET/PUT/DELETE /companies/{company}/credential` y rotación; entrega de `SriRobotCredentials` a `StartSriSync` vía `ReadSriCredential` (auditado) y `POST /sync-runs` con `Idempotency-Key`; borrado al archivar empresa; nunca en logs. **Bloqueante de M2.** Completado 2026-11-09 |
| F1-07 | Auditoría base | 0,5 d | `audit_logs` + `owen-it/laravel-auditing` en `Company`, `SriCredential`, `Membership`, `Invitation`; eventos explícitos de login y acceso a credencial; `GET /audit-logs` (`AuditView`); secretos redactados. **Se entrega junto con F1-06.** Completado 2026-11-09 |
| F1-08 | Reverb | 0,5 d | Config, `routes/channels.php` con `private-tenant.{tenantId}.company.{companyId}`, autorización por membresía y empresa, estado de conexión. **Habilita F2-09b.** Completado 2026-11-09 |
| F1-09 | Convenciones de API | 1 d | `problem+json` (RFC 9457) global, paginación cursor/offset uniforme, filtros declarativos, resources, OpenAPI con Scramble versionado en el repo y validado en CI. **Base de F2-09 y F4** |
| F1-10 | Provisión inicial | 0,5 d | Comando idempotente `app:provision` (tenant por defecto, catálogos, owner e invitación). La primera empresa se crea desde la UI (US-06). Sin registro público en el MVP. **Se ejecuta antes de F1-11.** Completado 2026-11-09 (PR #47); catálogos en F3-01 |
| F1-11 | Deploy a staging | 1 d | Staging temporal en una EC2 `t3.micro` con Docker Compose, Neon y DuckDNS/TLS (ADR-0008); `docker-compose.staging.yml`, Caddy, entrypoint, deploy por script y workflow manual. Producción sigue en Cloud. Runbook: `docs/13-aws-staging-deployment.md` |

**Pruebas obligatorias**
- **Aislamiento:** suite `Isolation` con dos tenants (aunque la UI solo cree uno); todo endpoint `show/update/delete` devuelve 404 para recursos ajenos.
- **Tenant-ready:** `TenantContext` liga el tenant por defecto en modo single, falla sin contexto y no permite mezclar datos al cambiar de tenant en tests.
- **Unitarias:** `Ruc` (válidos/inválidos por tipo), `ClaveAcceso` (módulo 11), normalización de identificaciones, reglas de régimen.
- **Feature:** provisión inicial, invitación (token de un solo uso), login, 2FA (habilitar/validar/recovery codes), reset, expiración de sesión, cambio de contraseña.
- **Policies:** matriz rol × acción en test de tabla.
- **Cifrado:** la credencial no aparece en `json_encode`, logs ni auditoría; rotación de clave no rompe lecturas.
- **Arquitectura (Pest arch):** `Domain` no depende de `Http`; ningún controlador supera 15 líneas de lógica.

**Criterio de salida (M1)**
- Demo en staging: provisionar el estudio por comando, invitar usuario, activar 2FA, registrar empresa con RUC válido, ver auditoría.
- 0 warnings de PHPStan, cobertura ≥ 80 %, suite de aislamiento en verde.

## 4. Fase 2 — Ingesta SRI (3 semanas)

**Objetivo:** descargar comprobantes reales de forma confiable, idempotente y observable.

| ID | Tarea | Est. | Detalle |
|---|---|---|---|
| F2-01 | Puerto `SriGateway` | 1 d | Contrato + `LambdaSriGateway` + `FakeSriGateway` (fixtures); DTOs de request/response |
| F2-02 | Layout S3 del robot | 1 d | `{ambiente}/{ruc}/{periodo}/` con TXT, `-claves.json` y `manifest.json`; checksums calculados por la app; bucket separado para el robot |
| F2-03 | `SriSyncRun` y orquestación | 2 d | Máquina de estados, `StartSriSync`, división de rangos > 93 días, `Idempotency-Key`, cancelación |
| F2-04 | Callback firmado | 1 d | Endpoint `POST /webhooks/sri-robot`, HMAC + timestamp + dedupe `event_id`, límite de tamaño, 202 |
| F2-05 | Reconciliación | 1 d | `sri:reconcile-runs` cada 10 min: valida manifiesto/TXT/claves, llena `sri_sync_run_files` y deja el run en `parsing` |
| F2-06 | Parser XML | 3 d | Factura, NC, ND, liquidación, retención; DTOs; validación de totales; XXE-safe; errores por documento |
| F2-07 | Parser de manifiesto/TXT | 1 d | Índice del robot, rutas, codificación, archivos corruptos |
| F2-08 | Persistencia idempotente | 2 d | `upsert` por `access_key`; ítems, impuestos, retenciones; deduplicación; `source` |
| F2-09 | Progreso en vivo | 1 d | **F2-09a (P0):** endpoint de estado con contadores + polling cada 5 s; entrega valor sin depender de Reverb. **F2-09b (P1):** eventos Reverb + canales privados. La tarea se cierra cuando ambas partes están verdes |
| F2-10 | Resiliencia | 1,5 d | Reintentos con backoff, fallos parciales, archivos inválidos, timeouts, run `partial` |
| F2-10d | Identidad de ejecución y sondeo sin callback | 1 d | Manifiesto v2 con `ejecucion`/`ejecuciones[]`, validación de `event_id` y frescura por `generadoEn`, comando `sri:import-manual`, `AwaitSriRobotResultJob` y reconciliación cada 5 min. **Completado (PRs #76, #77 y #89)** |
| F2-11 | Límites y cuotas | 0,5 d | Concurrencia 1 por RUC, rate limit configurable, bloqueo de runs solapados |
| F2-12 | Observabilidad | 0,5 d | Logs con `run_id`, métricas de duración/volumen, alerta de fallos |
| F2-13 | Carga manual de XML (`US-14`) | 2 d | Upload de XML autorizado, validación de estructura/clave/totales, `source=manual_upload`, cuarentena si hay inconsistencias, límites XXE/zip bomb. **Nueva tarea derivada del backlog** |
| F2-14 | Cancelación de run | 1 d | Endpoint idempotente → `cancelled`, detención de batches en curso y acción en el monitor. **Nueva tarea derivada de F2-03** |
| F2-15 | Runbook de runs huérfanos | 0,5 d | Procedimiento de `sri:recover-run`, alerta por umbral y prueba de restauración. **Nueva tarea derivada de F2-10c** |

**Pruebas obligatorias**
- **Fixtures** por tipo de comprobante (anonimizados) + casos borde: NC que ajusta factura, ICE, propina, descuento global, tarifa 0 %, divisas extranjeras no soportadas, XML truncado, clave inválida, totales inconsistentes.
- **Idempotencia:** ejecutar el mismo run dos veces produce los mismos registros (`assertDatabaseCount`).
- **Contrato:** `Http::fake` verifica payload de invocación (credencial sellada, bucket, callback) y manejo de errores de AWS.
- **Integración:** callback con firma válida/invalida/expirada/repetida.
- **Fallos:** un XML corrupto no tumba el run; queda en `sri_sync_run_files` con motivo.
- **Rendimiento:** importar 5.000 comprobantes desde fixtures en < 10 min con 3 workers (benchmark en staging).
- **Seguridad:** el payload del job y los logs no contienen credenciales.

**Criterio de salida (M2)**
- Sincronización real de un mes completo en staging, con > 99 % de documentos importados y run `completed`.
- Repetición del mismo run sin duplicados.
- Kill del worker a mitad de proceso → el run se reanuda o queda `partial` con detalle, sin corrupción.

## 5. Fase 3 — Motor fiscal, reglas e IA (3 semanas)

**Objetivo:** convertir comprobantes en datos fiscales clasificados, con riesgos detectados y revisión humana.

| ID | Tarea | Est. | Detalle |
|---|---|---|---|
| F3-01 | Catálogos versionados | 2 d | `tax_rates`, `form_lines`, `retention_rates`, `personal_expense_caps` con `valid_from/valid_to`; seeds por año. **Completado 2026-11-13 (PR #60):** carga idempotente, `catalogs:validate`, integración con `app:provision` y firma del contador pendiente |
| F3-02 | Motor de reglas | 2 d | Reglas por tenant/globales, prioridad, match por descripción/proveedor/código, dry-run, detección de conflictos, cache con invalidación. **Completado 2026-11-14:** `expense_categories`, `classification_rules` y `classifications`; motor con `contains`/`starts_with`/`exact`/`regex`/`provider_ruc`/`item_code`, precedencia y desempate tenant > global, caché versionada, CRUD con dry-run y 409 por conflicto, clasificación automática al persistir comprobantes; pendiente firma de deducibilidad |
| F3-03 | Clasificador IA | 2 d | Contrato `Classifier`, fake, adaptador OpenAI/Anthropic, JSON schema, validación, 1 reintento, timeout, circuit breaker. **Completado 2026-11-14:** `Classifier` con DTOs de solicitud/resultado, prompt versionado `v1`, validación estricta (categoría del catálogo, confianza 0..1, claves exactas), adaptadores OpenAI (JSON Schema) y Anthropic, `FakeClassifier` determinista, reintento solo ante salida inválida, timeout/429/5xx como indisponibilidad y circuit breaker por tenant; integrado como respaldo del motor de reglas con umbral `auto`/`review` y degradación sin bloquear la importación. Persistencia de `llm_calls`, presupuesto y caché por hash quedan para F3-04 |
| F3-04 | Gobierno de IA | 1,5 d | Caché por hash (el prompt versionado se adelantó a F3-03), `llm_calls` con tokens/coste/latencia, presupuesto por tenant, degradación a revisión manual. **Completado 2026-11-14:** `llm_calls` (hash de entrada, modelo, prompt, tokens, `cost_micros`, latencia, estado y `error_code`) enlazado desde `classifications.llm_call_id`; `GovernedClassifier` resuelve caché → presupuesto → proveedor, reutiliza clasificaciones exitosas con `method=cache` sin coste y corta la IA al agotar `LLM_MONTHLY_BUDGET_USD` registrando `budget_exceeded`; aviso al 80 % en el log; coste calculado con enteros desde una tabla de precios por modelo. La capa gratuita (Groq/Gemini) se usa cambiando `LLM_DRIVER=openai` y `OPENAI_BASE_URL`, sin adaptador nuevo |
| F3-05 | Revisión humana | 1,5 d | Cola de revisión, aceptar/rechazar/cambiar categoría, acciones masivas, auditoría de cambios. **Completado 2026-11-16:** `GET /api/v1/classifications` (prioriza `needs_review`, filtra por estado, método, empresa, texto y revisado), `PATCH /api/v1/classifications/{classification}` y `POST /api/v1/classifications/bulk-review` (lote máximo 100, resultado por ítem); aceptar deja el ítem `manual` y rechazar lo devuelve a `pending` sin categoría; `Classification` es auditable y conserva el origen de la sugerencia; permiso `fiscal_documents.review` |
| F3-06 | Riesgos fiscales | 2,5 d | Empresas fantasma (lista SRI), duplicados, totales inconsistentes, comprobantes fuera de período, retenciones faltantes, topes de gastos, proveedor recurrente sin RUC válido. **Completado 2026-11-20 (PR #97):** `fiscal_findings` y `ghost_companies`; `DetectFiscalRisks` con `TOTALS_MISMATCH`, `INVALID_RUC`, `GHOST_COMPANY` y `DUPLICATE_SEQUENTIAL`; auto-resolución de riesgos técnicos objetivos; `GET/PATCH /api/v1/fiscal-findings` y detección manual; integración best-effort en `FinalizeSriRun`; comando `fiscal:detect-risks`. `OUT_OF_PERIOD`, `MISSING_RETENTION` y `EXPENSE_CAP_EXCEEDED` quedan reservados (dependen de la convención del contador y de F5). **Severidades pendientes de firma** |
| F3-07 | Proporcionalidad IVA | 1 d | Factor 6 decimales, reglas de redondeo, casos 0 %/15 %, test de tabla. **Completado 2026-11-15:** value object `Money` (centavos + `brick/money`, HALF_UP en un solo lugar, enmienda del ADR-0002) y `Proportionality` (`factor()` 6 decimales HALF_UP, `complement()` que garantiza `1.000000` exacto y `apply()` a un importe); tabla de 11 casos con 0 %, 15 %, mixtos, período sin ventas y límites de redondeo. Uso en el casillero del 104 pendiente de la convención que firme el contador |
| F3-08 | Evaluación offline | 1 d | Dataset dorado, script `php artisan ai:evaluate`, reporte de accuracy/precisión/coste, ejecución semanal programada. **Bloqueada por datos (2026-11-21):** no existe dataset dorado en el repositorio, `docs/reports/` solo contiene el README y falta el informe de F0-05. Sin esta tarea el criterio de salida M3 no es demostrable |
| F3-09 | Administración de catálogos | 0,5 d | Endpoints de solo lectura + comando de importación desde CSV oficial. **Pendiente:** hoy solo existen `catalogs:validate` y el seeder; no hay API para consultar vigencias desde la UI |

**Pruebas obligatorias**
- **Calculadoras:** tablas de casos (entrada → salida esperada) para proporcionalidad, retenciones y topes; cubrir 0, 5, 12 y 15 % y cambios de vigencia a mitad de período.
- **Reglas:** precedencia, empate de prioridad, regex inválida, regla desactivada, aislamiento entre tenants.
- **IA:** adaptador con respuestas grabadas (válida, inválida, timeout, 429, JSON truncado); el sistema nunca lanza excepción al usuario, degrada a revisión.
- **Presupuesto:** al superar el límite no se hacen llamadas y se registra el motivo.
- **Riesgos:** cada regla con caso positivo y negativo; sin falsos positivos en el dataset dorado.
- **Evaluación:** accuracy ≥ 92 % global y ≥ 95 % de precisión en categorías "deducible"; el test falla si baja del umbral (marcado `@group=ai-eval`, se ejecuta nightly).

**Criterio de salida (M3)**
- ≥ 70 % de ítems clasificados automáticamente en una muestra real, con accuracy ≥ 92 %.
- Todas las alertas de riesgo con acción sugerida y enlace al comprobante.
- Coste medio de clasificación < USD 0,002 por ítem.

## 6. Fase 4 — Frontend SPA (3 semanas)

**Objetivo:** una interfaz rápida, oscura y accesible que haga visible todo lo anterior.

| ID | Tarea | Est. | Detalle |
|---|---|---|---|
| F4-01 | Fundaciones | 1 d | Vue 3 + TS strict, Vite, Tailwind 4, router, Pinia, TanStack Query, cliente HTTP con CSRF/errores, i18n. **Parcial:** Vite, Vue, Tailwind, router, Pinia y Vitest están hechos; el cliente HTTP aún descarta los `errors` de `problem+json` y no soporta `204` (se corrige en F4-03a). **Pendiente:** TanStack Query e i18n |
| F4-02 | Design system | 2 d | **Completado 2026-11-16:** tokens semánticos dark-first, tipografía, `prefers-reduced-motion` y foco visible en `resources/css/app.css`; `AppField`, `AppButton`, `AppInput`, `AppSelect`, `AppCombobox`, `AppDateRange`, `AppTable`, `AppBadge`, `AppModal`, `AppToastHost` y `AppDrawer` con Vitest; `AppLayout`/`AppStatusBadge` migrados a tokens · 50 tests · ver `docs/14-design-system.md`. **Superado por F4-04:** los wrappers propios se sustituyeron por Nuxt UI (ADR-0009) |
| F4-03 | Auth y primer acceso | 1,5 d | Login, 2FA, invitación, recuperación, primer contribuyente (el estudio ya está provisionado). **En curso 2026-11-21:** login, 2FA, recuperación y restablecimiento están hechos. **Slices pendientes:** **F4-03a** gestión de contribuyentes en la UI (alta, edición, archivo y estado vacío); **F4-03b** = F4-13 credencial SRI en la UI; **F4-03c** invitaciones en la UI (invitar y aceptar). Hoy las tres solo existen por API |
| F4-04 | Layout | 1 d | **Completado 2026-11-19 (PR #90):** layout del template Dashboard de Nuxt UI 4.13 (`UDashboardGroup`, `UDashboardSidebar` colapsable/redimensionable, `UDashboardPanel`, `UDashboardNavbar`, `UDashboardToolbar`, `UDashboardSearch`) y tema **Mint** (`primary: teal`, `neutral: olive`, radio `0.75rem`, controles píldora, Nunito Variable con pesos 500/600/700/800). Nuevos `AppShell`, `AppPage`, `AppSidebar`, `AppWorkspaceMenu`, `AppUserMenu`, `AuthLayout` y `lib/navigation.ts` (nav + paleta ⌘K compartidas); el selector de tema pasa al menú de usuario y se eliminan `AppThemeToggle`, `AppSidebar` y `AppWorkspaceSwitcher` antiguos. Páginas de panel, bandeja, sincronización, 2FA y auth migradas a utilidades semánticas de Nuxt UI. `useTheme` sigue gestionando claro/oscuro con `colorMode: false`. ADR-0009 enmendado y `docs/14-design-system.md` reescrito. 43 tests frontend, `vue-tsc` y ESLint en verde |
| F4-05 | Bandeja de comprobantes | 3 d | **Completado 2026-11-16 (PR #72):** `GET /api/v1/fiscal_documents` (cursor + filtros por empresa, estado, tipo, emisor, fechas), `GET /api/v1/fiscal_documents/{fiscal_document}` con líneas/impuestos/retenciones; `FiscalDocumentInboxPage` con filtros, paginación «Cargar más», drawer de detalle y selección múltiple. Migrado a `FiscalDocument` en PR #91 |
| F4-06 | Monitor de sincronización | 2 d | **Completado (PRs #73–#75):** `SyncMonitorPage` con formulario en una fila (empresa, tipos de documento como multiselección, desde/hasta), ayuda contextual para forzar re-descarga, progreso en vivo con polling (`poll_after_ms`) + archivos con errores, totales, historial y detalle de run. Store `sync` con `startSync` (Idempotency-Key), `loadRun`, `startPollingIfNeeded`. Los tipos enviados al robot usan `SriDocumentType::syncableCodes()` (sin guía de remisión `06`, que el portal no ofrece); el error del robot se muestra en el run. Si el robot publica solo XML, la recuperación importa con esos archivos en vez de esperar `robot_timeout`. **Refinamiento UI 2026-11-17:** la vista se dividió en `components/sri/SriSyncRunHistory.vue` y `components/sri/SriSyncRunDetailDrawer.vue` (la página solo orquesta formulario, polling y selección); textos de dominio en `lib/sri.ts`, incluido `syncErrorLabel` para que el detalle nunca muestre `error_code` crudo; el drawer limpia el run al cerrarse (`clearSelection`) y muestra loader al reabrir. **Fix 2026-11-18:** el progreso de `downloading` no cuenta los XML `missing`/`invalid` como avance y el activo se queda en 99 %; el aviso transitorio de `incomplete_package` del parser ya no se muestra mientras el run sigue activo; el parser solo considera incompleto un tipo declarado en el manifiesto, no un tipo solicitado sin comprobantes. **Refinamiento 2026-11-18:** el historial mantiene la lista en pantalla durante el refresco automático (solo muestra «Cargando…» en la primera carga) y cada item muestra el ID completo con el período en la misma línea en escritorio y en tres líneas en móvil; los comprobantes `updated` cuentan en el avance del item |
| F4-07 | Revisión de clasificación | 2 d | **Completado 2026-11-20 (PR #98):** `ClassificationReviewPage` con cola que prioriza `needs_review`, filtros por empresa/método/texto, confianza y método visuales, atajos `j`/`k`/`Enter`/`a`/`r`/`espacio`, aceptación y rechazo en bloque con resultado parcial (`failed`) y creación de regla desde un ítem. Store `classifications` y componentes en `components/classifications/`; ruta `/classifications` y entrada en la navegación |
| F4-08 | Dashboards | 1,5 d | IVA ventas vs. compras por mes, top proveedores, topes de gastos personales, alertas. **Depende de F5-01:** hoy `DashboardPage` solo muestra ceros o datos de `?preview=1` (`dashboardPreview.ts`); los KPIs deben salir de `period_aggregates` y las alertas de `fiscal_findings` |
| F4-08b | Bandeja de hallazgos | 1 d | **Nueva tarea derivada de F3-06:** UI para `GET/PATCH /api/v1/fiscal-findings` (reconocer, resolver, descartar con motivo) y para disparar la detección manual. El contrato ya existe y no tiene interfaz |
| F4-09 | Vista 104 (estructura) | 1,5 d | Casilleros, totales, drill-down a facturas, estados de borrador (datos reales llegan en F5-02/F5-06) |
| F4-10 | Calidad de UI | 1 d | Estados vacíos/carga/error, responsive, foco visible, contraste AA, `prefers-reduced-motion` |
| F4-11 | Pruebas frontend | 1,5 d | Vitest + MSW para componentes/composables, Playwright para 5 flujos críticos y `axe` en las pantallas principales |
| F4-12 | Indicador de Reverb | 0,5 d | **Nueva tarea derivada de F1-08/F2-09b:** estado de conexión y reconexión del WebSocket en el monitor. Hoy el polling cubre el caso, por eso es P2 |
| F4-13 | Credenciales SRI en la UI | 1 d | **Nueva tarea derivada de F1-06 (= F4-03b):** formulario de alta, rotación, borrado y estado de la credencial (`GET/PUT/DELETE /companies/{company}/credential`). Sin ella no se puede iniciar una sincronización real desde la app |

**Pruebas obligatorias**
- Componentes con estados (loading/empty/error/success) y accesibilidad (roles, labels, foco).
- Contratos API mockeados con MSW; un test falla si el tipo generado desde OpenAPI cambia sin actualizar el cliente.
- E2E: login+2FA, crear empresa, iniciar sync, revisar clasificación, abrir 104 y exportar.
- `axe` sin violaciones críticas en las 4 pantallas principales.
- Lighthouse ≥ 90 en performance/accesibilidad/best practices.

**Criterio de salida (M4)**
- Un contador externo completa el flujo principal sin ayuda, en staging, en < 15 min.
- Sin errores de consola; reconexión de Reverb manejada con indicador visual.

## 7. Fase 5 — Declaraciones e informes (3 semanas)

**Objetivo:** generar borradores fiscalmente correctos, auditables y exportables.

| ID | Tarea | Est. | Detalle |
|---|---|---|---|
| F5-01 | Agregados por período | 2 d | `tax_periods`, agregados materializados por empresa/período, recálculo idempotente |
| F5-02 | Formulario 104 | 3 d | Casilleros, arrastre de crédito tributario, proporcionalidad, retenciones, ajustes por NC/ND, validaciones |
| F5-03 | Formulario 103 | 2 d | Retenciones de IR/IVA por código, consolidado mensual, cruce con comprobantes |
| F5-04 | Formulario 102/101 | 2,5 d | Gastos personales por categoría, topes anuales, proyección, advertencias de exceso |
| F5-05 | Ciclo de vida | 1,5 d | `draft → in_review → approved → filed`, versionado, inmutabilidad, motivo de cambio |
| F5-06 | Drill-down y reconciliación | 1,5 d | `declaration_line_entries`, reporte de diferencias, bloqueo de cierre con diferencias no justificadas |
| F5-07 | Exportaciones | 1,5 d | PDF con branding, Excel con hojas por formulario, CSV de ATS (opcional MVP+) |
| F5-08 | Validación contable | 1 d | 12 escenarios dorados revisados por el contador; firma en `docs/fiscal-signoff.md` |

**Pruebas obligatorias**
- **Golden files:** un test por escenario (ventas 0 %/15 %, compras con crédito, NC, retenciones, período sin ventas, crédito anterior, proporcionalidad, RIMPE, exceso de topes) comparando el formulario completo.
- **Invariantes:** suma de líneas = total; suma de bases = casillero; activos/pasivos cuadran; sin floats.
- **Versionado:** declaración cerrada no editable; nueva versión conserva la anterior y enlaza `supersedes_id`.
- **Reconciliación:** inyectar una factura descuadrada bloquea el cierre con mensaje claro.
- **Export:** PDF/XLSX generado, legible y con las cifras correctas (snapshot de texto, no de bytes).
- **Permisos:** `assistant` no puede aprobar; `viewer` no exporta datos sensibles.

**Criterio de salida (M5)**
- El contador firma que los 12 escenarios coinciden con su cálculo manual.
- Tiempo de preparación de un 104 reducido ≥ 60 % vs. línea base manual (medido en UAT).

## 8. Fase 6 — Hardening, beta y lanzamiento (2 semanas)

| ID | Tarea | Est. | Detalle |
|---|---|---|---|
| F6-01 | Seguridad | 2 d | Checklist ASVS L2, CSP/HSTS/rate limiting, escaneo de dependencias, revisión de permisos, prueba de acceso cruzado ampliada |
| F6-02 | Rendimiento | 2 d | EXPLAIN de consultas top, índices, N+1, k6 con 100 usuarios concurrentes, ajuste de workers y pooling |
| F6-03 | Observabilidad | 1 d | Sentry, Pulse, alertas, dashboards, log drain, presupuesto de alertas |
| F6-04 | Backups y DR | 1 d | Verificar PITR, dump lógico a object storage, **restauración real** cronometrada, RPO/RTO medidos |
| F6-05 | Runbooks | 1 d | Incidentes, rollback, caída del SRI, Lambda caída, fuga de credencial, baja/exportación del estudio, rotación de claves |
| F6-06 | Legal y soporte | 1 d | Términos, privacidad, DPA, retención, proceso de soporte y SLA |
| F6-07 | Beta | 3 d | 3–5 estudios reales, UAT guiada, triage de feedback, corrección de bloqueantes |
| F6-08 | Go/No-Go | 0,5 d | Checklist de lanzamiento firmado |

**Checklist de lanzamiento**
- [ ] CI verde en `main`, cobertura ≥ 80 %, PHPStan sin errores, `composer audit` limpio.
- [ ] Migraciones ensayadas en staging con datos representativos; rollback probado.
- [ ] Backups automáticos activos y restauración verificada.
- [ ] Alertas funcionando (probar una alerta real de cola y una de error 500).
- [ ] Runbooks revisados por alguien que no los escribió.
- [ ] Aviso de privacidad y consentimiento de credenciales publicados.
- [ ] Límite de gasto configurado en Laravel Cloud y en el proveedor de IA.
- [ ] Buckets S3 por entorno con IAM mínimo, SSE, versionado y lifecycle verificados (ver `docs/aws-s3-setup.md`).
- [ ] Plan de soporte y canal para clientes beta.

## 9. Calendario de sprints

> **Revisión 2026-11-21.** El calendario original (S0–S10) y las etapas A–F quedan como referencia histórica: F0, F1 y F2 están cerradas, F3 está funcionalmente completa (7/9) y F4 va por 5/11. Rige el calendario siguiente. El criterio es entregar un producto operable de punta a punta (M4) y abrir F5 con agregados, sin esperar la firma del contador para lo que no la necesita.

| Etapa | Prioridad | Foco | Demo / criterio |
|---|---|---|---|
| **G1** (2026-11-21 →) | **P0** | Docs sync · **F4-03a** empresas en UI · **F4-03b (F4-13)** credencial SRI en UI · **F4-03c** invitaciones en UI | Un usuario provisionado registra una empresa, carga su credencial e invita a un contador **sin salir de la app** |
| **G2** | **P1** | **F5-01** agregados por período · **F4-08** dashboards reales · **F4-08b** bandeja de hallazgos · **F3-09** catálogos de solo lectura | Panel con IVA ventas/compras y alertas reales; F3 cerrada salvo evaluación |
| **H** | **P1/P2** | **F5-02** 104 · **F4-09** vista 104 · **F5-03** 103 · **F5-04** 102/101 | **M5:** borradores validados por el contador (requiere firma previa de la convención) |
| **I** | **P2/P3** | **F5-05…F5-08** · **F4-10/F4-11** · **F3-08** (con dataset) | **M4/M5:** flujo completo con calidad y evaluación IA |
| **J** | **P3** | **F6-01…F6-08** · F1-03g · F2-14 · F2-15 | **M6:** beta con 3–5 estudios y Go/No-Go |

**Reglas de secuencia:**
- **F4-03a antes de F4-03b/c:** sin empresas no hay dónde colgar credenciales ni invitaciones.
- **F4-13 (credencial) antes de cualquier demo real de sync:** el monitor ya existe, pero no hay forma de cargar la credencial desde la UI.
- **F5-01 antes de F4-08:** los dashboards deben nacer de `period_aggregates`; no se vuelve a usar `dashboardPreview` como estado por defecto.
- **F5-02 espera la firma del contador** (proporcionalidad y casilleros del 104). La firma se solicita en paralelo desde el inicio de G1; es el camino crítico de F5.
- **F3-08 no bloquea G:** requiere un dataset dorado que aún no existe. Se prepara en paralelo y se ejecuta en la etapa I.
- **F4-01 (TanStack/i18n)** no bloquea: F4-03a corrige lo imprescindible del cliente HTTP (`problem+json` y `204`).
- **F2-14/F2-15/F1-03g** son deuda P2 acotada; no bloquean M4 ni M5.

Con 2 personas, el carril frontend (F4-03/F4-08) corre en paralelo al fiscal (F5-01/F5-02) y el total se estima en **≈8–10 semanas** desde esta revisión, condicionado a la firma del contador.

## 10. Estrategia de pruebas por fase (resumen)

| Fase | Unitarias | Feature | Contrato | E2E | No funcionales |
|---|---|---|---|---|---|
| F0 | — | — | spike script | — | latencia Lambda, coste IA |
| F1 | RUC, clave, regímenes | auth, 2FA, policies, **aislamiento** | OpenAPI | login y empresa | — |
| F2 | parsers, DTOs, totales | sync, callback, idempotencia | AWS/Lambda fake | sync desde UI | 5k docs < 10 min |
| F3 | calculadoras, reglas, proporcionalidad | clasificación, revisión, riesgos | LLM fake/grabado | revisión de ítems | accuracy, coste |
| F4 | composables, formatos | — | tipos OpenAPI | 5 flujos | Lighthouse, axe |
| F5 | 104/103/102 | ciclo de vida, permisos, reconciliación | — | exportar | golden files |
| F6 | — | regresión completa | — | smoke | k6, restore, ASVS |

## 11. Definition of Ready / Done

**Ready (antes de tomar una historia):**
- Criterios de aceptación en Gherkin y casos borde identificados.
- Contrato de API acordado (request/response/errores).
- Dependencias externas disponibles (fixtures, acceso, credenciales de prueba).
- Impacto fiscal revisado si aplica.

**Done (para cerrar una tarea):**
- Código + tests (unitarios y de feature) que fallan sin el cambio.
- `make check` verde y CI verde.
- Sin nuevos warnings de PHPStan; sin `dd()`/`dump()`; sin secretos.
- Migración compatible hacia atrás (expand/contract) y reversible.
- Documentación/ADR actualizados; OpenAPI regenerado si cambió el contrato.
- Desplegado en staging y verificado manualmente.
- **Fiscal:** validado por el contador con cifras de ejemplo.

## 12. Registro de riesgos

| # | Riesgo | Prob. | Impacto | Mitigación | Señal temprana |
|---|---|---|---|---|---|
| R1 | El portal del SRI cambia y rompe el robot | Alta | Alto | Robot aislado en Lambda, contrato versionado, monitoreo de fallos, plan de contingencia con carga manual de XML | Tasa de fallos > 10 % |
| R2 | Bloqueo de la cuenta del contribuyente por exceso de intentos | Media | Alto | Concurrencia 1 por RUC, rate limit, backoff, no reintentar credenciales inválidas | Errores 401/429 del robot |
| R3 | Credenciales SRI filtradas | Baja | Crítico | Cifrado dedicado, sealed box, accesos auditados, rotación, secretos fuera del repo | Alertas de acceso anómalo |
| R4 | Fuga de datos al activar multitenancy | Media | Crítico | Esquema tenant-ready desde el MVP, scope global, suite de aislamiento con dos tenants, revisión de PR, RLS en F6, pentest | Test de aislamiento rojo |
| R11 | Deuda al activar el modo multi (UI/onboarding) | Media | Medio | Checklist de ADR-0006 revisado en cada fase; `TENANCY_MODE` y `TenantContext` ya presentes | Activación multi con más de 5 días de trabajo |
| R5 | Error en cálculos fiscales | Media | Crítico | Catálogos versionados, golden files, firma del contador, reconciliación bloqueante | Diferencias en reconciliación |
| R6 | Coste de IA descontrolado | Media | Medio | Presupuesto por tenant, caché, modelo pequeño, evaluación de coste | Alerta 80 % presupuesto |
| R7 | Límites de Laravel Cloud (90 s Flex, 1 MiB) | Media | Medio | Jobs pequeños, IDs en payload, colas Pro para reportes | Jobs que expiran |
| R8 | Latencia alta hacia Ecuador | Media | Medio | Región más cercana, índices, caché, paginación por cursor | p95 > 500 ms |
| R9 | Dependencia de una sola persona en el robot | Media | Alto | Documentar el robot, acceso a AWS, runbook, pruebas de humo | Bus factor |
| R10 | Cambios normativos (tarifas, formularios) | Alta | Alto | Catálogos con vigencia, proceso anual de actualización, feature flag por año fiscal | Publicación oficial del SRI |

## 13. KPIs del MVP

| KPI | Objetivo | Fuente |
|---|---|---|
| Comprobantes importados / run | ≥ 99 % de los disponibles | `sri_sync_runs` |
| Tiempo de sync (5.000 docs) | < 10 min p95 | métricas |
| Clasificación automática | ≥ 70 % de ítems | `classifications` |
| Accuracy de clasificación | ≥ 92 % | evaluación semanal |
| Tiempo de preparación del 104 | −60 % vs. manual | UAT |
| Diferencias de reconciliación | 0 sin justificar | reporte |
| Disponibilidad | ≥ 99,5 % | Cloud/Sentry |
| Coste IA por ítem | < USD 0,002 | `llm_calls` |
| Satisfacción beta | ≥ 4/5 | encuesta |

## 14. Gestión de cambios fiscales (proceso permanente)

1. Suscripción a publicaciones del SRI y revisión mensual de fichas técnicas.
2. Todo cambio entra como PR que modifica **catálogos versionados** + tests golden, nunca lógica dispersa.
3. Se publica con `valid_from` futuro y `feature flag` por año fiscal.
4. Se ejecuta la suite completa de escenarios y se compara contra el cálculo del contador.
5. Se documenta en `docs/fiscal-signoff.md` con fecha, fuente oficial y responsable.

## 15. Fuera de alcance del MVP (backlog posterior)

Conciliación bancaria · emisión de comprobantes · integración con Contífico/SIIGO · OCR · app móvil · multi-país · portal del cliente final · facturación/planes por suscripción (Stripe) · ATS completo · activos fijos · nómina.

## 16. Desviaciones conscientes respecto al plan original

| Plan original | Decisión en este plan | Motivo |
|---|---|---|
| `stancl/tenancy:dev-master` | `stancl/tenancy:^3.10` | `dev-master` produce builds no reproducibles; 3.10.1 soporta Laravel 13.24 |
| Multitenancy multi-base de datos | Base única + `tenant_id` (ADR-0001) | Laravel Cloud adjunta una BD por entorno; crear/migrar BD en runtime añade riesgo y coste |
| Multitenancy desde el MVP | **Single-tenant** con esquema tenant-ready (ADR-0006) | El MVP atiende un estudio por despliegue; `tenant_id`/scope se implementan desde el día 1 para activar multi sin migrar datos |
| Laravel 11 / PHP 8.3 | Laravel 13.24+ / PHP 8.5 | Versión soportada actualmente; las colas administradas de Cloud requieren ≥ 13.19 |
| JWT o Sanctum sin definir | Sanctum (cookie + tokens) | Revocación inmediata, cookies `HttpOnly`, sin refresh tokens |
| Frontend "API only" separado | SPA Vue en `resources/js` (ADR-0004) | Un solo deploy, sin CORS ni doble pipeline; la API sigue versionada para móvil/integraciones |
| Formularios 104 y 102 | 104 + **103** + 102/101 | Sin el 103 las retenciones practicadas no se declaran; es parte del mismo flujo mensual |
| Particionar `fiscal_documents` desde el día 1 | Sin particionado en el MVP | Complejidad prematura: índices + agregados materializados cubren 1 M docs/año |
| Horizon para colas | Colas administradas + monitoreo Cloud | Horizon no es compatible con las colas gestionadas de Laravel Cloud |
| Descarga SRI dentro de Laravel | Lambda + S3 + callback firmado | Aislamiento del scraping, límites de 15 min y credenciales fuera de la app |
| Sin plan de pruebas | Pirámide completa + golden files + aislamiento | La exactitud fiscal y la confidencialidad son requisitos de producto, no "calidad extra" |
| F2-09 como una sola tarea | **F2-09a** endpoint + polling (P0) y **F2-09b** Reverb (P1) | No bloquear la UI del monitor por una dependencia de infraestructura |
| F1-03 como una sola tarea | Slice a–g (identidad base, reset, roles/2FA, invitaciones, sesiones, 2FA owner, validaciones) | La identidad resultó más grande de lo estimado; permite PRs pequeños y revisables |
| F4 después de F3 | Mínimo de F4 (design system, layout, bandeja, monitor) en P0; resto en P2 | Sin UI no se puede demostrar M1/M2 ni validar con usuarios |
| Carga manual de XML fuera de F2 | Nueva **F2-13** (`US-14`, Must) | El backlog la exige como contingencia operativa del MVP |
| Un solo calendario S0–S10 | Calendario revisado A–F (2026-11-09) y después G–J (2026-11-21) | Refleja el avance real y las dependencias descubiertas |
| F4-03 como una sola tarea | Slices a (empresas), b (credencial SRI = F4-13) y c (invitaciones) | La UI de auth ya estaba hecha; lo que falta son tres flujos independientes y revisables por separado |
| F4-08 después de F5-02 | F4-08 depende solo de **F5-01** (agregados) | Los dashboards no necesitan el 104, pero sí datos reales por período |
| F3-08 "1 día" | F3-08 bloqueada hasta tener dataset dorado | El alcance real incluye 300 descripciones reales anonimizadas y criterio de categoría correcta; sin eso no se puede medir accuracy |
| Documentos de etapa (`docs/11`, `docs/15`) | Archivados como referencia histórica | Contradicen la regla de mantener el plan en `docs/02` y el avance en `docs/09` |

## 17. Prioridades actuales (revisión 2026-11-21)

### Hecho (histórico)
F0 · F1 (11/11, F1-03g pendiente) · F2 (12/12 + F2-13) · F3-01…F3-07 · F4-02, F4-04, F4-05, F4-06 y F4-07 · F1-11 staging · F2-09a/b · F1-03e/f.

### P0 — Crítico: producto operable (Etapa G1)
| Orden | Tarea | Motivo |
|---|---|---|
| 1 | Sincronizar `docs/02` y `docs/09` con el estado real | Se estaba planificando sobre datos falsos: la "siguiente etapa" documentada ya estaba hecha |
| 2 | **F4-03a** Empresas en la UI (alta/edición/archivo + estado vacío) | US-06 es **Must**; hoy la única vía es la API. Sin empresas no hay M4 |
| 3 | **F4-03b = F4-13** Credencial SRI en la UI | Sin credencial el monitor de sync no se puede usar desde cero |
| 4 | **F4-03c** Invitaciones en la UI (invitar y aceptar) | US-02 **Must**; hoy solo API |

### P1 — Alto: cerrar F3 y abrir F5 (Etapa G2)
**F5-01** agregados por período · **F4-08** dashboards reales · **F4-08b** bandeja de hallazgos · **F3-09** catálogos de solo lectura · firma del contador (camino crítico de F5).

### P2 — Medio: declaraciones y calidad (Etapas H–I)
F5-02…F5-08 · F4-09 vista 104 · F4-10/F4-11 calidad y pruebas · F3-08 evaluación (con dataset) · F4-12 indicador Reverb · F1-03g invitaciones · F2-14 cancelación · F2-15 runbook.

### P3 — Cierre del MVP
F6-01…F6-08 (hardening, beta, Go/No-Go).

**Siguiente acción inmediata (2026-11-21).** Orden de la etapa (igual en `docs/09`):

| # | Rama | Tarea |
|---|---|---|
| 0 | `docs/sync-progress-2026-11-21` | Sincronizar `docs/02`, `docs/09`, `docs/03`, `docs/fiscal-signoff` y archivar `docs/11`/`docs/15` |
| 1 | `feat/F4-03-companies-ui` | F4-03a · slice 1: cliente HTTP (`problem+json`, `204`) y store de empresas |
| 2 | `feat/F4-03-companies-page` | F4-03a · slice 2: página, formulario y archivo con confirmación de RUC |
| 3 | `feat/F4-03-companies-onboarding` | F4-03a · slice 3: ruta, navegación y estado vacío del panel |
| 4 | `feat/F4-13-credential-ui` | F4-03b/F4-13 · credencial SRI |
| 5 | `feat/F4-03-invitations-ui` | F4-03c · invitaciones |
| 6 | `feat/F5-01-period-aggregates` | F5-01 · agregados por período |
| 7 | `feat/F4-08-dashboard` | F4-08 · dashboards reales + alertas |

El estado vivo de cada fila vive en `docs/09-progress.md`; no se mantienen documentos de etapa separados (`docs/11` y `docs/15` quedan archivados como referencia histórica).
