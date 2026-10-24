# 03 — Modelo de datos

## 1. Convenciones

| Regla | Decisión |
|---|---|
| Claves primarias | `bigint` identity (rendimiento de joins) + `public_id` ULID (26 chars) expuesto en la API |
| Tenant | `tenant_id` en **toda** tabla de negocio, FK con `cascadeOnDelete`, primer campo de cada índice compuesto |
| Fechas | `timestamptz` en UTC. La UI muestra `America/Guayaquil` |
| Dinero | `bigint` en centavos, sufijo `_cents`; nunca `float`/`double` |
| Cantidades | `numeric(18,6)` |
| Tasas/porcentajes | `numeric(9,6)` (0.150000 = 15 %) |
| Estados | `varchar(32)` + enum de PHP + `CHECK` en base. **No** usar `ENUM` nativo de Postgres |
| JSON | `jsonb` solo para datos crudos/externos (XML normalizado, payloads, configuración). Lo que se filtra o agrega va en columnas |
| Soft delete | Solo en entidades editables por el usuario (`companies`, `invoice_items`, `classification_rules`). Comprobantes y declaraciones se archivan por estado |
| Nombres | Tablas en plural snake_case; FKs `<singular>_id`; booleanos `is_*`/`has_*` |
| Migraciones | Una migración por cambio lógico; nunca editar una migración ya desplegada |

> **Modo de tenancy (ADR-0006):** el MVP es **single-tenant**: `tenants` tendrá una sola fila (el estudio), provisionada por comando. `tenant_id` se conserva en todas las tablas de negocio para que el modo multi se active sin migrar datos. La tabla `domains` y el slug de subdominio quedan reservados para ese modo; en el MVP el acceso es por un dominio único.

## 2. Vista general

```
tenants ──< domains
   │
   ├──< memberships >── users
   ├──< companies ──< sri_credentials
   │        │
   │        ├──< invoices ──< invoice_items ──< classifications
   │        │        ├──< invoice_taxes
   │        │        ├──< invoice_payments
   │        │        ├──< retention_lines
   │        │        └──< invoice_relations
   │        ├──< sri_sync_runs ──< sri_sync_run_files
   │        ├──< fiscal_periods ──< period_aggregates
   │        ├──< declarations ──< declaration_lines ──< declaration_line_entries
   │        └──< fiscal_findings
   │
   ├──< classification_rules
   ├──< llm_calls
   └──< audit_logs

Catálogos globales: sri_document_types, tax_rates, retention_rates,
form_lines, expense_categories, personal_expense_caps, ghost_companies
```

## 3. Tablas centrales

### `tenants`
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| public_id | char(26) unique | ULID |
| name | varchar(160) | Estudio contable |
| slug | varchar(63) unique | Identificador estable del estudio; en modo multi será el subdominio. Regex `^[a-z0-9]([a-z0-9-]{1,61}[a-z0-9])?$`; reservadas: `www`, `api`, `app`, `admin`, `soporte` |
| status | varchar(20) | `trial`, `active`, `suspended`, `cancelled` |
| plan | varchar(32) | `starter`, `pro`, `enterprise` |
| settings | jsonb | Límites, branding, flags |
| trial_ends_at | timestamptz null | |
| created_at / updated_at | timestamptz | |

Índices: `unique(slug)`, `index(status)`.

### `domains`
`id`, `tenant_id` (nullable para dominios centrales), `domain` unique, `is_primary`, timestamps.

> **Reservada para el modo multi.** En el MVP single-tenant no se usa: la aplicación se sirve en un único dominio (`APP_URL`).

### `users` (identidad global)
`id`, `public_id`, `name`, `email` unique, `password` (Argon2id), `email_verified_at`, `two_factor_secret` (cifrado), `two_factor_recovery_codes` (cifrado), `two_factor_confirmed_at`, `last_login_at`, `status`, `locale`, timestamps, `deleted_at`.

> El modelo admite que un usuario pertenezca a varios estudios (consultores). En el MVP single-tenant cada usuario tiene una única membresía activa; los datos de negocio **no** cuelgan del usuario, sino del tenant.

### `memberships`
`id`, `tenant_id`, `user_id`, `role` (`owner/accountant/assistant/viewer`), `status` (`invited/active/suspended`), `invited_by`, `accepted_at`, timestamps. `unique(tenant_id, user_id)`.

### `company_user` (scoping fino)
`company_id`, `user_id`, `can_approve` (bool), timestamps. Si un usuario no tiene filas, hereda acceso a todas las empresas del tenant según su rol.

### `invitations`
`id`, `tenant_id`, `email`, `role`, `token_hash` unique, `expires_at`, `accepted_at`, `invited_by`.

## 4. Empresas y credenciales

### `companies`
| Columna | Tipo | Notas |
|---|---|---|
| id / public_id | bigint / char(26) | |
| tenant_id | bigint FK | |
| ruc | char(13) | Validado (módulo 11) |
| legal_name | varchar(300) | Razón social |
| trade_name | varchar(300) null | |
| regime | varchar(32) | `general`, `rimpe_emprendedor`, `rimpe_negocio_popular`, `persona_natural`, `sociedad` |
| is_accounting_required | boolean | Obligado a llevar contabilidad |
| is_withholding_agent | boolean | Agente de retención |
| withholding_resolution | varchar(64) null | N.º de resolución |
| economic_activity_code | varchar(10) null | Código SRI/CIIU |
| address / email / phone | | |
| fiscal_year_start_month | smallint | 1 por defecto |
| status | varchar(20) | `active`, `inactive`, `archived` |
| settings | jsonb | Preferencias de clasificación, alertas |
| created_by | bigint FK users | |
| timestamps / deleted_at | | |

Índices: `unique(tenant_id, ruc)`, `index(tenant_id, status)`.

### `sri_credentials`
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint | |
| tenant_id / company_id | FK | `unique(company_id)` (una credencial activa) |
| username | varchar(13) | RUC o usuario del portal |
| password_encrypted | text | Cifrado con `SRI_CREDENTIALS_KEY` (AES-256-GCM + sobre con clave dedicada) |
| key_version | smallint | Para rotación |
| status | varchar(20) | `active`, `invalid`, `locked`, `revoked` |
| last_used_at / last_success_at / last_failure_at | timestamptz | |
| failure_count | smallint | Se reinicia al éxito |
| rotated_at | timestamptz | |
| created_by | bigint | |
| timestamps | | |

**Nunca** se serializa. El modelo implementa `$hidden = ['password_encrypted']` y un accessor de dominio que descifra solo bajo demanda.

### `sri_credential_accesses`
`id`, `tenant_id`, `company_id`, `user_id` null, `purpose` (`sync`, `rotation`, `support`), `ip`, `created_at`. Retención 1 año.

### `company_obligations`
Obligaciones por empresa/año: `company_id`, `fiscal_year`, `form_code` (`104`, `103`, `102`, `101`, `ats`), `frequency` (`monthly`, `annual`), `due_day`, `is_applicable`, `source` (`catalog`, `manual`). Unique `(company_id, fiscal_year, form_code)`.

## 5. Catálogos versionados (globales)

### `sri_document_types`
`code` (`01`,`02`,`03`,`04`,`05`,`06`,`07`), `name`, `is_deductible_supported`, `valid_from`, `valid_to`.

### `tax_rates` (IVA)
`id`, `code` (`IVA`), `rate` numeric(9,6), `sri_percentage_code` (`0`,`5`,`12`,`15`), `label`, `valid_from` date, `valid_to` date null, `legal_basis`.

> El código de porcentaje del SRI (`codigoPorcentaje`) es distinto de la tarifa. Ambos se guardan.

### `retention_rates`
`id`, `tax_type` (`IR`,`IVA`), `code` (código de retención), `rate`, `applies_to` (`services`,`goods`,`rent`,`professional`…), `valid_from`, `valid_to`, `legal_basis`.

### `form_lines`
Definición de casilleros por formulario y vigencia: `form_code`, `line_code`, `section`, `label`, `data_type` (`amount`,`count`,`rate`), `is_required`, `display_order`, `valid_from`, `valid_to`. Unique `(form_code, line_code, valid_from)`.

### `expense_categories`
`code`, `name`, `is_personal_deductible`, `is_business_deductible`, `parent_id`, `icon`, `valid_from`, `valid_to`. Árbol: alimentación, vivienda, salud, educación, vestimenta, turismo, operativo, activo fijo, no deducible.

### `personal_expense_caps`
`fiscal_year`, `category_code`, `cap_cents`, `basic_food_basket_cents`, `source`, `published_at`. Unique `(fiscal_year, category_code)`.

### `ghost_companies`
`ruc` PK, `legal_name`, `resolution`, `published_at`, `source_url`, `status`, `imported_at`. Se refresca por comando desde la fuente oficial.

## 6. Comprobantes

### `invoices`
| Columna | Tipo | Notas |
|---|---|---|
| id / public_id | | |
| tenant_id / company_id | FK | |
| document_type | char(2) | `01`,`03`,`04`,`05`,`06`,`07` |
| access_key | char(49) | |
| authorization_number | varchar(64) null | |
| establishment / emission_point / sequential | varchar(3)/varchar(3)/varchar(9) | |
| issue_date | date | Fecha de emisión (local Ecuador) |
| authorization_date | timestamptz null | |
| status | varchar(20) | `authorized`, `cancelled`, `rejected`, `pending` |
| environment | char(1) | `1` pruebas, `2` producción |
| direction | varchar(16) | `received` (compra) / `issued` (venta) |
| issuer_ruc / issuer_name | char(13) / varchar(300) | |
| receiver_ruc / receiver_name | | |
| subtotal_cents | bigint | Sin impuestos |
| discount_cents | bigint | |
| tax_cents | bigint | IVA total |
| ice_cents / irbpnr_cents / tip_cents | bigint | |
| total_cents | bigint | |
| currency | char(3) | `USD` |
| exchange_rate | numeric(18,6) null | Reservado |
| payment_method | varchar(32) null | |
| xml_path | varchar(512) | S3 key |
| xml_sha256 | char(64) | Integridad |
| xml_size | integer | |
| raw | jsonb | XML normalizado (sin credenciales) |
| source | varchar(20) | `sri_robot`, `manual_upload`, `api` |
| first_seen_at / imported_at | timestamptz | |
| sync_run_id | bigint null | |
| timestamps | | |

Índices:
- `unique(tenant_id, company_id, access_key)` — **idempotencia**.
- `index(tenant_id, company_id, issue_date desc)`.
- `index(tenant_id, company_id, issuer_ruc)`.
- `index(tenant_id, company_id, document_type, status)`.
- `gin(raw jsonb_path_ops)` solo si se consulta por contenido.

### `invoice_items`
| Columna | Tipo | Notas |
|---|---|---|
| id / tenant_id / invoice_id | | |
| line_number | smallint | |
| main_code / auxiliary_code | varchar(50) null | |
| description | varchar(1000) | Tal como viene del XML |
| normalized_description | varchar(1000) | Sin acentos, mayúsculas, espacios colapsados (para reglas/caché) |
| quantity | numeric(18,6) | |
| unit_price | numeric(18,6) | |
| discount | numeric(18,6) | |
| subtotal_cents | bigint | `precioTotalSinImpuesto` |
| total_cents | bigint | Con impuestos |
| expense_category_id | bigint null | Clasificación vigente (desnormalizada para filtros) |
| classification_status | varchar(20) | `pending`, `auto`, `review`, `manual` |
| timestamps / deleted_at | | |

Índice: `(tenant_id, invoice_id)`, `(tenant_id, expense_category_id)`, `(tenant_id, classification_status)`.

### `invoice_taxes`
`id`, `tenant_id`, `invoice_id`, `tax_code` (`IVA`,`ICE`,`IRBPNR`), `sri_percentage_code`, `rate`, `base_cents`, `amount_cents`, `tax_rate_id` null.

### `invoice_payments`
`id`, `tenant_id`, `invoice_id`, `payment_method`, `total_cents`, `term_days`, `due_date`.

### `retention_lines`
Para documentos tipo `07`: `id`, `tenant_id`, `invoice_id`, `tax_type` (`IR`,`IVA`), `retention_code`, `base_cents`, `rate`, `amount_cents`, `retention_rate_id`, `document_number`.

### `invoice_relations`
`id`, `tenant_id`, `source_invoice_id` (NC/ND), `target_invoice_id` (factura), `relation_type` (`credit_note`, `debit_note`), `amount_cents`, `reason`.

### `sri_sync_runs`
| Columna | Tipo | Notas |
|---|---|---|
| id / public_id | | |
| tenant_id / company_id | FK | |
| requested_by | bigint | |
| status | varchar(20) | `queued`,`invoking`,`downloading`,`parsing`,`completed`,`partial`,`failed`,`cancelled` |
| date_from / date_to | date | |
| periods | jsonb | Períodos `YYYY-MM` solicitados; base de la reconciliación S3 |
| document_types | jsonb | |
| idempotency_key | varchar(64) unique | Evita doble cobro/ejecución |
| lambda_request_id | varchar(128) null | |
| s3_prefix | varchar(512) null | |
| totals | jsonb | `{found, imported, skipped, failed}` |
| error_code / error_message | | Sin PII |
| started_at / finished_at | timestamptz | |
| timestamps | | |

Índices: `(tenant_id, company_id, created_at desc)`, `index(status)` para reconciliación.

### `sri_sync_run_files`
`id`, `run_id`, `tenant_id`, `file_name`, `s3_key`, `checksum` (SHA-256 hex),
`document_type`, `status`, `error_message`, `processed_at`. Unique
`(run_id, s3_key)`; índice `(tenant_id, status)`. Estados:
`pending`, `available`, `missing`, `invalid`, `processed`. La reconciliación
F2-05 registra manifiesto, TXT y JSON de claves; el parser F2-06 los consume.

### `sri_run_events`
Log de progreso: `id`, `run_id`, `tenant_id`, `level`, `message`, `context jsonb`, `created_at`. Retención 90 días.

## 7. Clasificación

### `classifications`
| Columna | Tipo | Notas |
|---|---|---|
| id / tenant_id | | |
| invoice_item_id | FK | Unique por ítem (clasificación vigente) |
| expense_category_id | FK | |
| method | varchar(16) | `rule`, `cache`, `llm`, `manual` |
| confidence | numeric(5,4) | 0..1 |
| status | varchar(16) | `suggested`, `accepted`, `rejected`, `needs_review` |
| rule_id / llm_call_id | bigint null | |
| prompt_version | varchar(32) null | |
| reviewed_by / reviewed_at | | |
| timestamps | | |

Se conserva historial en `audit_logs`; para análisis de calidad se agrega `classification_feedback` (`item_id`, `from_category`, `to_category`, `user_id`, `created_at`).

### `classification_rules`
`id`, `tenant_id` (null = global), `name`, `priority` (menor = primero), `match_type` (`contains`,`starts_with`,`regex`,`exact`,`provider_ruc`), `pattern`, `expense_category_id`, `is_active`, `created_by`, `hits_count`, `last_matched_at`, timestamps.

Índice: `(tenant_id, is_active, priority)`. Validación: regex compilada al guardar; conflictos detectados en dry-run.

### `llm_calls`
`id`, `tenant_id`, `company_id` null, `purpose` (`classify_item`), `provider`, `model`, `prompt_version`, `input_hash` char(64), `input_tokens`, `output_tokens`, `cost_micros`, `latency_ms`, `status`, `error_code`, `created_at`.

Índice `(tenant_id, created_at)` para el presupuesto. **No** se guarda la descripción cruda si contiene datos personales; se guarda el hash y, opcionalmente, una versión redactada.

## 8. Fiscal

### `fiscal_periods`
`id`, `tenant_id`, `company_id`, `year`, `month`, `status` (`open`,`locked`,`filed`), `opened_at`, `locked_at`, `locked_by`, `checksum` null. Unique `(company_id, year, month)`.

### `period_aggregates`
Agregados materializados por período y dimensión:
`id`, `tenant_id`, `company_id`, `fiscal_period_id`, `direction` (`purchases`,`sales`), `document_type`, `tax_code`, `sri_percentage_code`, `rate`, `base_cents`, `tax_cents`, `retention_cents`, `documents_count`, `computed_at`, `version`.

Unique `(fiscal_period_id, direction, document_type, tax_code, sri_percentage_code)`. Se recalculan completos (idempotente) cuando cambia un comprobante del período.

### `declarations`
| Columna | Tipo | Notas |
|---|---|---|
| id / public_id | | |
| tenant_id / company_id / fiscal_period_id | FK | |
| form_code | varchar(4) | `104`,`103`,`102`,`101` |
| status | varchar(16) | `draft`,`in_review`,`approved`,`filed`,`superseded` |
| version | smallint | 1..n |
| totals | jsonb | Resumen para listados |
| snapshot | jsonb | Entrada congelada del cálculo (auditoría) |
| checksum | char(64) | Hash del snapshot + líneas |
| supersedes_id | bigint null | |
| created_by / approved_by / approved_at | | |
| filed_at / filed_reference | | Presentación manual |
| pdf_path / xlsx_path | varchar(512) | |
| timestamps | | |

Unique `(company_id, fiscal_period_id, form_code, version)`. Índice `(tenant_id, status)`.

### `declaration_lines`
`id`, `tenant_id`, `declaration_id`, `line_code`, `section`, `label`, `value_cents`, `value_count`, `display_order`. Unique `(declaration_id, line_code)`.

### `declaration_line_entries`
Trazabilidad para drill-down: `id`, `tenant_id`, `declaration_line_id`, `source_type` (`invoice`,`invoice_item`,`retention`,`adjustment`), `source_id`, `amount_cents`, `sign` (+1/−1), `meta jsonb`.

Índice `(declaration_line_id)`, `(source_type, source_id)`.

### `fiscal_findings`
`id`, `tenant_id`, `company_id`, `fiscal_period_id` null, `code` (`DUPLICATE_INVOICE`,`TOTALS_MISMATCH`,`GHOST_COMPANY`,`MISSING_RETENTION`,`OUT_OF_PERIOD`,`EXPENSE_CAP_EXCEEDED`,`INVALID_RUC`…), `severity` (`info`,`warning`,`critical`), `title`, `description`, `source_type/source_id`, `metadata jsonb`, `status` (`open`,`acknowledged`,`resolved`,`dismissed`), `resolved_by/at`, timestamps.

Unique parcial `(tenant_id, code, source_type, source_id)` para no duplicar hallazgos abiertos.

## 9. Auditoría y operación

### `audit_logs`
`id`, `tenant_id` null, `user_id` null, `event` (`created`,`updated`,`deleted`,`login`,`export`,`credential_access`,`declaration_approved`…), `auditable_type/id`, `old_values jsonb`, `new_values jsonb`, `ip`, `user_agent`, `request_id`, `created_at`.

Índices: `(tenant_id, created_at)`, `(auditable_type, auditable_id)`. Sin secretos: los valores sensibles se redactan.

### `idempotency_keys`
`key` varchar(64) PK, `tenant_id`, `user_id`, `endpoint`, `request_hash`, `response_code`, `response_body jsonb`, `created_at`, `expires_at`.

### `exports`
`id`, `public_id`, `tenant_id`, `user_id`, `type` (`declaration_pdf`,`invoices_xlsx`,`ats_csv`), `status`, `path`, `size`, `expires_at`, `error`, timestamps.

### `failed_jobs`, `job_batches`, `cache`, `sessions`
Tablas estándar de Laravel.

## 10. Invariantes (verificadas en tests y en runtime)

1. `invoices.total_cents = subtotal_cents − discount_cents + tax_cents + ice_cents + irbpnr_cents + tip_cents`.
2. `invoices.tax_cents = sum(invoice_taxes.amount_cents)`.
3. `invoice_items.subtotal_cents` suma el subtotal de la factura (±0,01 por redondeo del emisor; se registra la diferencia).
4. Ningún `invoice_items` de un tenant referencia una `invoice` de otro (FK compuesta o validación en Action + test).
5. `declaration_lines` suma exactamente el total del formulario.
6. La suma de `declaration_line_entries.amount_cents * sign` por línea = `value_cents` (o está documentada la parte no trazable, p. ej. arrastre de crédito).
7. Una `declaration` en `approved`/`filed` es inmutable (`updating` lanza excepción).
8. `sri_sync_runs.idempotency_key` es único y se valida antes de invocar la Lambda.
9. `classifications` tiene una sola fila vigente por `invoice_item_id`.
10. Toda fila de negocio tiene `tenant_id` no nulo y coincide con el de su padre.

## 11. Estrategia de migraciones

- **Expand/contract** en tres releases para cambios destructivos: (1) agregar columna/tabla y escribir en ambos lados; (2) backfill por job + validar; (3) eliminar lo viejo.
- Backfills por lotes (`chunkById`) en jobs, nunca en la migración.
- Índices grandes: `CREATE INDEX CONCURRENTLY` en migración con `public $withinTransaction = false;`.
- `migrate --force` solo en deploy command; nunca `migrate:fresh` fuera de local/testing.
- Prohibido renombrar columnas en un solo deploy.
- Cada migración incluye `down()` funcional o justificación explícita de irreversibilidad.

## 12. Particionamiento y crecimiento

**No particionar en el MVP.** Con 1 M comprobantes/año, índices bien elegidos y agregados materializados, Postgres responde sobradamente.

Disparadores para revisar (medir, no adivinar):
- `invoices` > 10 M filas o p95 de listados > 500 ms tras optimizar índices.
- Tablas de auditoría/eventos > 50 M filas → particionar por mes o mover a almacenamiento analítico.

Cuando se active, particionar `invoices` por rango de `issue_date` (año), incluyendo la clave de partición en los índices únicos: `unique(tenant_id, company_id, issue_date, access_key)`. Evaluar `pg_partman` o migración manual con `ATTACH PARTITION`.

## 13. Retención y borrado

| Dato | Retención | Acción |
|---|---|---|
| XML original (S3) | ≥ 7 años (normativa tributaria) | Lifecycle a almacenamiento frío; borrado al eliminar tenant con confirmación |
| Facturas/ítems | ≥ 7 años | Archivar; borrado físico solo por solicitud legal |
| Declaraciones y PDFs | ≥ 7 años | Inmutables |
| `audit_logs` | ≥ 1 año (recomendado 3) | Purga programada |
| `sri_run_events` | 90 días | Purga |
| `llm_calls` | 1 año | Agregados anuales, luego purga |
| Credenciales SRI | Mientras la empresa esté activa | Borrado inmediato al archivar/eliminar empresa |
| Datos personales (LOPDP) | Según base legal | Exportación y borrado por tenant |

## 14. Seeds mínimos

- Catálogos: tipos de documento, tarifas IVA con vigencia, tasas de retención, casilleros 104/103/102/101, categorías de gasto, topes del año.
- Tenant demo (`demo`) con 2 empresas y 50 comprobantes de fixture para desarrollo.
- Usuarios demo: `owner@demo.test`, `contador@demo.test` (password solo en local).
- **Nunca** seeds con datos reales ni credenciales reales.
