# 15 — Plan de implementación: F4-07 + F3-06 + sincronización de documentación

> **Objetivo:** cerrar el último ítem P1 del frontend (revisión de clasificación) y avanzar el motor fiscal con la detección de riesgos (F3-06), dejando la documentación viva al día. Una tarea = un PR; las subtareas de este plan se ejecutan en PRs separados y pequeños.

## 0. Contexto y estado de partida

- F0, F1 y F2 completadas. F3 en 6/9 (F3-06, F3-08, F3-09 pendientes). F4 con F4-02, F4-04, F4-05 y F4-06 ya mergeadas, pero `docs/09-progress.md` no refleja los PRs #72–#96 ni la refactorización `Invoice → FiscalDocument`.
- Backend disponible para F4-07: `GET/PATCH /api/v1/classifications` y `POST /api/v1/classifications/bulk-review` (F3-05), `ClassificationResource`, `ClassificationPolicy` (`fiscal_documents.view` / `fiscal_documents.review`).
- No existe aún `fiscal_findings` ni `ghost_companies` (solo están en `docs/03-data-model.md`). F3-06 requiere crearlos.

## 1. Orden de ejecución

| Orden | PR sugerido | Tarea | Carril | Motivo |
|---|---|---|---|---|
| 1 | `docs/sync-progress` | Sincronizar `docs/09-progress.md` con el estado real | Docs | Evita planificar sobre datos falsos |
| 2 | `feat/F3-06-fiscal-findings-migration` | Migración + modelo + factory de `fiscal_findings` y `ghost_companies` | Backend | Base de todo lo demás |
| 3 | `feat/F3-06-detect-risks` | `DetectFiscalRisks` + reglas de detección + comando `fiscal:detect-risks` | Backend | Lógica de negocio aislada y testeable |
| 4 | `feat/F3-06-findings-api` | Endpoints de listado/actualización + policy + resource | Backend | Contrato para frontend y F4-08 |
| 5 | `feat/F4-07-classification-review` | Store + página de revisión con teclado, bulk y creación de regla | Frontend | Depende de (1) y del contrato de F3-05 |
| 6 | `docs/update-progress` | Actualizar `docs/09-progress.md` y `docs/02-implementation-plan.md` (F3-06, F4-07) | Docs | Cierre de ciclo |

Los pasos 2–4 pueden solaparse con el 5 si hay dos personas; el frontend mockea el contrato con MSW hasta que el backend esté merged.

## 2. Tarea D0 — Sincronizar `docs/09-progress.md`

**Archivos:** `docs/09-progress.md`.

**Cambios:**
- Actualizar `Última actualización` a la fecha actual.
- Marcar como completadas (con evidencia/PR) las tareas que ya están en `main`: F4-05 (PR #72), F4-06 (PR #73–#75), el refactor `Invoice → FiscalDocument` (PR #91) y los fixes de staging (#85–#96).
- Corregir el avance de F4: `4/11 + fundaciones/auth parciales` (F4-02, F4-04, F4-05, F4-06 completas; F4-01 y F4-03 parciales).
- Añadir al registro de PRs recientes las entradas #76/#77 (F2-10d) y #88–#96 si faltan.
- Mantener F3-06, F3-08, F3-09 y F4-07 como pendientes con su prioridad (P2/P1).

**Criterio de salida:** la tabla de resumen por fase coincide con el código de `main` (verificable con `git log`).

## 3. Tarea F3-06 — Riesgos fiscales (backend)

### 3.1 Alcance y decisiones

- **Incluido en este PR-set:**
  - `TOTALS_MISMATCH`: `fiscal_documents.totals_mismatch = true`.
  - `INVALID_RUC`: `issuer_ruc` no pasa la validación del validador `Ruc` (módulo 10/11).
  - `DUPLICATE_SEQUENTIAL`: mismo `(company_id, issuer_ruc, establishment, emission_point, sequential)` en más de un comprobante.
  - `GHOST_COMPANY`: `issuer_ruc` presente en `ghost_companies`.
- **Diferido explícitamente (no bloquea M3):**
  - `OUT_OF_PERIOD`, `MISSING_RETENTION` (requieren convención de período/retención firmada por el contador — se deja el `code` reservado y el detector devuelve `[]` para ellos).
  - `EXPENSE_CAP_EXCEEDED` (depende de F5).
  - Importación real de la lista oficial del SRI de empresas fantasma (se deja comando `ghost-companies:import` con CSV y un seeder de fixtures para tests).

### 3.2 Subtarea B1 — Migraciones y modelos

**Archivos nuevos:**
- `database/migrations/2026_11_20_000001_create_ghost_companies_table.php`
- `database/migrations/2026_11_20_000002_create_fiscal_findings_table.php`
- `app/Domain/Fiscal/Models/GhostCompany.php`
- `app/Domain/Fiscal/Models/FiscalFinding.php` (ubicar en `app/Domain/Fiscal/` o `app/Domain/FiscalDocuments/`; recomendado `Fiscal` por ser hallazgo transversal)
- `database/factories/FiscalFindingFactory.php`

**Esquema `ghost_companies`** (según `docs/03-data-model.md`):
- `ruc char(13) PK`, `legal_name varchar(300)`, `resolution varchar(128) null`, `published_at date`, `source_url varchar(512)`, `status varchar(20) default 'active'`, `imported_at timestamptz`, timestamps.

**Esquema `fiscal_findings`**:
- `id`, `tenant_id FK cascade`, `company_id FK cascade`, `fiscal_period_id FK nullable` (reservado), `code varchar(40)`, `severity varchar(16)` (`info|warning|critical`), `title varchar(200)`, `description text`, `source_type varchar(40)` (`fiscal_document`), `source_id bigint`, `metadata jsonb`, `status varchar(20)` (`open|acknowledged|resolved|dismissed`), `resolved_by FK users nullOnDelete`, `resolved_at timestamptz null`, timestamps.
- Índices: `(tenant_id, company_id, status)`, `(tenant_id, code, status)`.
- Unique parcial: `(tenant_id, code, source_type, source_id)` **solo para `status = 'open'`**. En Postgres usar `where status = 'open'` en un índice único parcial. En la migración: `$table->unique(['tenant_id','code','source_type','source_id'], 'fiscal_findings_open_unique')->where('status', 'open')` (Laravel soporta `->where` en unique desde 11; verificar; si no, crear con `DB::statement`).

**Modelo `FiscalFinding`:**
- `use BelongsToTenant, HasFactory;`
- Casts: `metadata => array`, `resolved_at => datetime`, enums `code`/`severity`/`status` como strings (o enums PHP nuevos `FiscalRiskCode`, `FiscalRiskSeverity`, `FiscalRiskStatus` en `app/Domain/Fiscal/Enums/`).
- Relaciones: `company()`, `resolver()`, `source()` polimórfica opcional (no obligatoria; guardar `source_type/source_id` y un accessor que resuelva `FiscalDocument` cuando aplique).
- Scopes: `open()`, `forCompany($id)`.

**Policy `FiscalFindingPolicy`:** `viewAny`/`view` con `FiscalDocumentsView`; `update` (acknowledge/resolve/dismiss) con `FiscalDocumentsReview`; `sameTenant` defensivo.

**Tests (B1):** migración reversible (`migrate:fresh` en testing), factory crea fila válida, unique parcial permite reabrir tras resolver (test con dos filas mismo source con status distinto).

### 3.3 Subtarea B2 — Detección

**Archivos nuevos:**
- `app/Domain/Fiscal/Actions/DetectFiscalRisks.php` (final readonly, `handle(Company $company, ?Carbon $periodStart = null, ?Carbon $periodEnd = null): FiscalRiskDetectionResult`)
- `app/Domain/Fiscal/Data/FiscalRiskDetectionResult.php` (contadores por código + lista de `FiscalFinding` creados/actualizados)
- `app/Domain/Fiscal/Services/FiscalRiskDetector.php` (o poner la lógica dentro del Action; recomendado: Action orquesta, detectores por código en `Services/Risks/*`)
- `app/Domain/Fiscal/Services/Risks/TotalsMismatchDetector.php`
- `app/Domain/Fiscal/Services/Risks/InvalidRucDetector.php`
- `app/Domain/Fiscal/Services/Risks/DuplicateSequentialDetector.php`
- `app/Domain/Fiscal/Services/Risks/GhostCompanyDetector.php`
- `app/Console/Commands/DetectFiscalRisksCommand.php` (`fiscal:detect-risks {company?} {--period=}`)

**Comportamiento:**
- Upsert idempotente: si ya existe un finding `open` con la misma `(tenant, code, source_type, source_id)`, no duplicar; si el riesgo desapareció, marcar `resolved` (o `dismissed`? usar `resolved` con `resolved_at` y `resolved_by = null` para auto-resolución, documentado).
- Severity: `TOTALS_MISMATCH` → critical; `GHOST_COMPANY` → critical; `INVALID_RUC` → warning; `DUPLICATE_SEQUENTIAL` → warning.
- Metadata: guardar datos útiles para UI (ej. `total_cents`, `expected_total_cents`, `duplicate_ids`).

**Tests (B2):** un test por detector con caso positivo y negativo; idempotencia (ejecutar dos veces no duplica); auto-resolución cuando el riesgo desaparece (ej. totals_mismatch corregido); aislamiento entre tenants (dos tenants, findings de uno no aparecen en el otro).

### 3.4 Subtarea B3 — API de findings

**Archivos nuevos/modificados:**
- `app/Http/Controllers/Api/V1/FiscalFindingController.php`
- `app/Http/Resources/FiscalFindingResource.php`
- `app/Http/Requests/FiscalFinding/UpdateFiscalFindingRequest.php`
- `routes/api.php` — agregar bajo el grupo tenant-aware:
  - `GET fiscal-findings` (filtros: `company_id`, `code`, `status`, `severity`)
  - `PATCH fiscal-findings/{fiscal_finding}` (`status` ∈ {acknowledged, resolved, dismissed} + `note` opcional en metadata)
  - `POST companies/{company}/fiscal-findings/detect` (dispara detección manual, devuelve resumen)
- Integración: llamar `DetectFiscalRisks` al finalizar un run de importación (en `FinalizeSriRunJob` o `ImportSriRun`) para que los hallazgos se generen automáticamente. Decisión: llamar en `FinalizeSriRunJob` solo si `status = completed|partial`, por empresa del run. Esto deja el run sin bloqueo y sin fallo si la detección lanza (capturar y loguear).

**Tests (B3):** feature tests de endpoints (403 sin permiso, 404 cross-tenant, 200 con filtros, PATCH cambia estado y audita), test de que la detección se dispara al finalizar un run (fake/queue).

### 3.5 Subtarea B4 — Documentación fiscal

- Añadir entrada en `docs/fiscal-signoff.md` reservando los códigos de riesgo y su severidad (pendiente de firma del contador, igual que F3-02/F3-07).
- Actualizar `docs/03-data-model.md` si se ajusta algún campo (probablemente ya está; verificar `fiscal_period_id` nullable).

## 4. Tarea F4-07 — Revisión de clasificación (frontend)

### 4.1 Contrato (ya existe)

- `GET /api/v1/classifications?filter[status]&filter[method]&filter[company_id]&filter[search]&filter[reviewed]&per_page` → paginado, prioriza `needs_review`.
- `PATCH /api/v1/classifications/{id}` body `{ decision: 'accept'|'reject', expense_category_id? }`.
- `POST /api/v1/classifications/bulk-review` body `{ ids: number[], decision, expense_category_id? }` → `{ data: { updated, failed: [{id, reason}] } }`.

### 4.2 Subtarea C1 — Store

**Archivo:** `resources/js/stores/classifications.ts` (+ spec).

- Estado: `items`, `loading`, `error`, `selectedIds` (Set), `filters` (status/method/company/search/reviewed), `pagination` (nextCursor o page).
- Acciones: `fetchQueue`, `accept(id, categoryId?)`, `reject(id)`, `bulkAccept(ids, categoryId?)`, `bulkReject(ids)`, `toggleSelection`, `clearSelection`.
- Usar `getJson`/`postJson`/`patchJson` del cliente HTTP existente (ver `lib/api.ts`).

### 4.3 Subtarea C2 — Página y componentes

**Archivos nuevos:**
- `resources/js/pages/ClassificationReviewPage.vue`
- `resources/js/components/classifications/ClassificationQueueTable.vue`
- `resources/js/components/classifications/ClassificationDetailDrawer.vue`
- `resources/js/components/classifications/ReviewActions.vue`
- `resources/js/components/classifications/CreateRuleFromItemDialog.vue`

**Comportamiento (según plan F4-07):**
- Cola con teclado: `j`/`k` para mover foco, `Enter` para abrir detalle, `a` aceptar sugerida, `r` rechazar, `space` seleccionar.
- Confianza visual: badge con color por umbral (≥0.9 verde, 0.7–0.9 ámbar, <0.7 rojo) y tooltip con método (`rule`/`cache`/`llm`).
- Aceptar en bloque: barra flotante cuando `selectedIds.size > 0` con "Aceptar sugeridas" y "Rechazar".
- Crear regla desde un ítem: diálogo que prellena `match_type` + patrón con la descripción normalizada y `expense_category_id` con la categoría confirmada; llama a `POST /api/v1/classification-rules` (ya existe).
- Estados: loading (skeleton), empty (sin pendientes), error (reintentar).
- Accesibilidad: roles, labels, foco visible, `prefers-reduced-motion` (ya en tokens).

### 4.4 Subtarea C3 — Rutas y navegación

- Agregar ruta `/classifications` (o `/review`) en `router` y entrada en `lib/navigation.ts` (sidebar + paleta ⌘K) con permiso `fiscal_documents.review` (ocultar a `viewer`).
- Guard de ruta que redirige a dashboard si no tiene permiso.

### 4.5 Subtarea C4 — Tests

- Vitest + MSW: store (fetch, accept, reject, bulk parcial con `failed`), componente (estados, teclado), accesibilidad (axe) sin violaciones críticas.
- Playwright: flujo "revisar clasificación" (uno de los 5 flujos críticos de F4-11) — puede quedar como parte de F4-11; aquí al menos smoke.

## 5. Criterios de aceptación

**F3-06:**
- `php artisan migrate:fresh` crea `fiscal_findings` y `ghost_companies` sin errores.
- `php artisan fiscal:detect-risks` sobre fixtures genera hallazgos para los 4 códigos y es idempotente.
- Endpoint `GET /api/v1/fiscal-findings` filtra por empresa/código/estado/severidad; `PATCH` cambia estado y queda auditado.
- Cross-tenant: un tenant no ve hallazgos del otro (404 en PATCH de recurso ajeno).
- `make check` verde; cobertura de los detectores ≥ 90 %.

**F4-07:**
- La página carga la cola priorizando `needs_review`, muestra confianza y método.
- Aceptar/rechazar individual y en bloque funciona, incluyendo resultados parciales (`failed`).
- Crear regla desde un ítem persiste y se refleja en la cola.
- Navegación por teclado (j/k/Enter/a/r/space) operativa y con foco visible.
- Vitest verde; `vue-tsc` y ESLint sin errores; sin errores de consola en navegador.

## 6. Riesgos y decisiones abiertas

- **Unique parcial en Postgres:** verificar que la versión de Laravel del proyecto soporte `->where` en `unique()`; si no, usar `DB::statement` en la migración (documentar en `down()`).
- **Auto-resolución:** marcar `resolved` automáticamente puede ocultar hallazgos que el contador quería revisar; alternativa es solo crear/actualizar y dejar la resolución manual. **Decisión propuesta:** auto-resolver solo `TOTALS_MISMATCH` y `DUPLICATE_SEQUENTIAL` (riesgos técnicos objetivos); `GHOST_COMPANY` e `INVALID_RUC` requieren acción humana y no se auto-resuelven. Confirmar con el contador.
- **Permisos de detección manual:** `POST .../detect` exige `FiscalDocumentsReview` (assistant puede disparar). Ajustar si se quiere restringir a accountant.
- **TanStack Query/i18n (F4-01):** siguen pendientes; F4-07 puede implementarse con el patrón actual (Pinia + `getJson`). No bloquea; se deja para un PR de fundaciones posterior.

## 7. Actualizaciones de documentación (obligatorias al cerrar)

- `docs/09-progress.md`: marcar F3-06 y F4-07 como completadas con evidencia; actualizar avance de F3 (7/9) y F4.
- `docs/02-implementation-plan.md`: mover F3-06 de P2 a completado en §17; añadir F4-07 a completados; actualizar el registro de PRs.
- `docs/fiscal-signoff.md`: registrar códigos de riesgo y severidad (pendiente de firma del contador).
- `docs/03-data-model.md`: verificar que `fiscal_findings` y `ghost_companies` coinciden con la migración (ya documentados; ajustar si cambia algún campo).
- `docs/14-design-system.md`: si F4-07 introduce componentes nuevos reutilizables, documentar tokens/uso.

## 8. Checklist de PR (por subtarea)

- [ ] `make check` verde (Pint, PHPStan 8, Pest).
- [ ] Tests nuevos que fallan sin el cambio.
- [ ] Sin secretos ni PII en fixtures/logs.
- [ ] Migración reversible con `down()`.
- [ ] OpenAPI regenerado si cambió el contrato (Scramble).
- [ ] Cross-tenant test para los nuevos endpoints.
- [ ] Docs actualizadas según §7.