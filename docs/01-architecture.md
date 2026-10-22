# 01 — Arquitectura y decisiones técnicas

> Documento de referencia para implementar Autotributo. Toda desviación debe registrarse como ADR en `docs/adr/`.

## 1. Alcance

**En alcance (MVP):**
- **MVP single-tenant**: un estudio contable por despliegue, con N contribuyentes (RUC). El esquema y el scoping quedan preparados para activar multitenancy sin migrar datos (ADR-0006).
- Ingesta automática de comprobantes electrónicos recibidos desde el portal del SRI (facturas, notas de crédito/débito, liquidaciones de compra y comprobantes de retención).
- Clasificación de ítems (reglas + IA) con revisión humana.
- Auditoría fiscal preventiva (duplicados, totales inconsistentes, proveedores en listas del SRI, topes de gastos personales).
- Borradores de **Formulario 104 (IVA)**, **Formulario 103 (retenciones)** y **Formulario 102/101 (Renta)**, con drill-down y exportación PDF/Excel.
- Panel en tiempo real del progreso de descargas.

**Fuera de alcance (explícito):**
- Emitir comprobantes electrónicos (no somos emisor).
- Conciliación bancaria, nómina, activos fijos, inventarios.
- Integración bidireccional con software contable de terceros (se deja el puerto `AccountingExport`).
- OCR de facturas físicas, app móvil nativa, multi-país.
- Presentación automática de declaraciones en el portal del SRI (solo generación y exportación asistida en MVP).

## 2. Contexto (C4 — nivel 1)

```
                       ┌──────────────────────────────┐
   Contador / Asistente│  SPA Vue 3 (dark, Laravel    │
   (navegador)         │  Cloud style) + Reverb       │
                       └──────────────┬───────────────┘
                                      │ HTTPS /api/v1 + WSS
                                      ▼
                       ┌──────────────────────────────┐
                       │  Autotributo API (Laravel 13)│
                       │  Laravel Cloud               │
                       └───┬───────┬───────┬──────────┘
                           │       │       │
              ┌────────────┘       │       └──────────────┐
              ▼                    ▼                      ▼
      ┌──────────────┐     ┌──────────────┐      ┌────────────────┐
      │ PostgreSQL   │     │ Object       │      │ LLM API        │
      │ (Neon)       │     │ Storage (S3) │      │ (OpenAI/Claude)│
      └──────────────┘     └──────┬───────┘      └────────────────┘
                                  │ XML/paquetes
                                  ▼
                         ┌──────────────────┐
                         │ AWS Lambda       │──────► Portal SRI
                         │ sri-txt-robot    │        (descarga)
                         └──────────────────┘
```

Actores externos: portal del SRI, proveedor de LLM, AWS (Lambda, S3, CloudWatch) y el correo transaccional (Resend en Cloud).

## 3. Stack y versiones

| Componente | Versión objetivo | Justificación / riesgo |
|---|---|---|
| Laravel | `^13.24` | Requerido por colas administradas de Laravel Cloud (≥13.19) y por `stancl/tenancy` 3.10.1 (soporte Laravel 13.24) |
| PHP | 8.5 | Versión por defecto en Laravel Cloud; 8.4 como plan B si una extensión falla |
| `stancl/tenancy` | `^3.10` (**no** `dev-master`) | Reproducibilidad y parches de seguridad; `dev-master` rompe builds aleatoriamente |
| PostgreSQL | 16 (local) / Serverless Postgres (Cloud) | JSONB, GIN, agregaciones fiscales; extensión `pgvector` disponible si se requiere búsqueda semántica |
| Valkey/Redis | Valkey 8 | Caché, sesiones y colas en local; en Cloud el driver es `cloud` (SQS) |
| Reverb | `laravel/reverb` ^1 | WebSockets administrados en Cloud |
| Sanctum | `laravel/sanctum` ^4 | Auth SPA por cookie (mismo dominio) + tokens para integraciones |
| Permisos | `spatie/laravel-permission` ^6 (teams) | Roles por tenant |
| DTOs | `spatie/laravel-data` ^4 | DTOs tipados para XML/SRI/LLM |
| Dinero | `brick/money` ^0.10 | Aritmética entera en centavos, sin floats |
| PDF | `barryvdh/laravel-dompdf` o `spatie/laravel-pdf` (Browsershot) | Decidir en Fase 5; Browsershot requiere binario en Cloud (validar) |
| Excel | `maatwebsite/excel` ^3.1 | Exportaciones y plantillas |
| Auditoría | `owen-it/laravel-auditing` ^13 | Trazabilidad de cambios |
| Frontend | Vue 3.5 + TypeScript 5 (strict) + Vite 7 | SPA en `resources/js` (ADR-0004) |
| UI | Tailwind 4 + shadcn-vue + Radix Vue | Estilo Laravel Cloud, accesible |
| Estado | Pinia + TanStack Query | Estado de UI vs. estado de servidor, sin duplicar caché |
| Gráficos | Chart.js 4 | Ligero y suficiente para tendencias |
| Validación FE | vee-validate + zod | Contratos compartidos con la API |
| Tests | Pest 4, PHPStan/Larastan 8, Pint, Vitest, Playwright, MSW | Ver `04-testing-strategy.md` |
| Observabilidad | Sentry + Laravel Pulse + log drain | Errores, métricas y trazas por tenant |

## 4. Tenancy: MVP single-tenant, esquema tenant-ready (ADR-0001 y ADR-0006)

### 4.1 Decisión

El MVP corre en modo **single-tenant**: un despliegue atiende a **un estudio contable**. No hay registro público de estudios, subdominios por tenant ni selector de estudio. Sin embargo, **toda** tabla de negocio lleva `tenant_id` y el scoping se implementa desde la primera migración, para que activar el modo multi sea un cambio de configuración, middleware y UI, no una migración de datos.

- `TENANCY_MODE=single` resuelve el tenant por defecto (`TENANCY_DEFAULT_SLUG`) y lo expone en `TenantContext`.
- `stancl/tenancy` se mantiene para ciclo de vida y contexto; **se desactiva el `DatabaseTenancyBootstrapper`** (no hay BD por tenant).
- Una sola base de datos por entorno (ADR-0001). El coste de crear/migrar una BD por cliente queda descartado.
- La tabla `domains`, el middleware `ResolveTenant` y el onboarding público quedan **reservados** para el modo multi.
- El modo multi se activa con `TENANCY_MODE=multi`; el tenant inicial ya existe y los datos ya están separados por `tenant_id`.

Checklist de activación y criterios de revisión: ADR-0006.

### 4.2 Por qué no BD por tenant (en Laravel Cloud)

| Criterio | BD por tenant | Esquema por tenant | **BD única + `tenant_id`** |
|---|---|---|---|
| Aislamiento | Máximo | Alto | Medio (compensado con tests + RLS) |
| Crear tenant en runtime | Requiere `CREATE DATABASE` | Requiere `CREATE SCHEMA` | INSERT |
| Laravel Cloud (Postgres serverless) | Las BD se crean por panel/API, no por SQL de la app; 500 BD por clúster | `CREATE SCHEMA` suele estar permitido al rol dueño | Sin fricción |
| Migraciones | N ejecuciones por deploy | N ejecuciones por deploy | 1 ejecución |
| Coste/arranque en frío (Neon escala a cero) | Se multiplica por tenant | 1 base | 1 base |
| Restauración puntual por tenant | Sencilla | Compleja | Requiere export lógico |
| Consultas entre tenants (soporte, métricas) | Complejas | Complejas | Triviales |

**Conclusión:** el coste operativo y el riesgo de plataforma de las alternativas superan el beneficio. En el MVP ni siquiera se aprovisiona un tenant por cliente: un despliegue equivale a un estudio. La base única con `tenant_id` es la decisión que permite crecer a multi-tenant sin reescribir el modelo. Se revisará si un cliente enterprise exige aislamiento físico (ruta de migración en ADR-0001).

### 4.3 Implementación

```php
// app/Domain/Tenancy/Models/Concerns/BelongsToTenant.php
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);           // filtra por tenant_id

        static::creating(function (Model $model) {
            $model->tenant_id ??= app(TenantContext::class)->id();
        });
    }
}
```

Reglas:
1. **Toda** tabla de negocio tiene `tenant_id` (FK `tenants.id`, `cascadeOnDelete`) e índice compuesto que empiece por `tenant_id`, incluso en modo single.
2. El scope global es obligatorio; para consultas de soporte se usa `withoutTenantScope()` con autorización explícita y log de auditoría.
3. Los modelos centrales (`Tenant`, `Domain`, `User`) **no** usan el trait.
4. Todo job, evento, notificación y comando debe pasar por `TenantAware` (`tenancy()->initialize()` + `finally { tenancy()->end(); }`). Un job sin contexto falla de forma ruidosa; hay un test que lo verifica.
5. En modo `single`, `TenantContext` se inicializa al arrancar la petición con el tenant por defecto; no hay resolución por subdominio. En modo `multi`, el middleware `ResolveTenant` (reservado) resuelve **antes** de autenticar y de cualquier consulta. En ambos casos el orden es: contexto de tenant → `EnsureTenantIsActive` → `auth`.
6. Los archivos se guardan bajo `tenants/{tenant_id}/...` también con un solo estudio; las claves de caché llevan prefijo del tenant.
7. Prohibido `DB::table()` sin `where tenant_id` (regla de PHPStan personalizada o test de arquitectura).
8. `TENANCY_MODE` solo admite `single` o `multi`; un valor inválido detiene el arranque.

### 4.4 Aislamiento como requisito de release

- Suite `tests/Feature/Isolation/*`: para **cada** endpoint con `{id}`, un test intenta acceder a un recurso de otro tenant y espera `404`.
- `TenantLeakTest`: crea dos tenants con datos homónimos y verifica que listados, búsquedas, exports y reportes solo devuelven lo propio.
- **Aunque el MVP sea single-tenant, la suite se mantiene:** es la prueba de que la preparación multi-tenant no tiene fugas y evita una regresión el día que se active el modo multi.
- `TenantContextTest`: en modo single se liga el tenant por defecto; sin contexto, cualquier consulta de negocio falla con una excepción explícita.
- Revisión obligatoria en el PR (checklist).
- Defensa en profundidad (Fase 6): RLS de PostgreSQL con `FORCE ROW LEVEL SECURITY` y `SET LOCAL app.tenant_id` dentro de transacción. **Antes de activarlo** hay que validar el modo de pooling de Neon (transaction pooling) y el rol usado por migraciones; ver ADR-0001.

## 5. Restricciones de Laravel Cloud que condicionan el diseño

| Restricción | Consecuencia de diseño |
|---|---|
| Filesystem efímero, por réplica | XML, PDFs, exports y temporales **siempre** en Object Storage (S3). Nunca `storage/app` como fuente de verdad |
| Colas administradas sobre SQS, *at-least-once* | Jobs **idempotentes**; deduplicación por clave natural (`access_key`, `run_id`) |
| Payload máximo ~1 MiB por job | Los jobs transportan **IDs y referencias S3**, nunca XML completos |
| Flex: 90 s por job; Pro: sin límite fijo | Jobs de parseo por archivo/lote pequeño; orquestador separa el trabajo. Un job = una unidad < 60 s |
| Sin Horizon ni `queue:failed/retry` con colas administradas | Monitoreo vía dashboard de Queues/API de Cloud; reintentos propios en dominio cuando haga falta |
| Delays ≤ 15 min en colas estándar (FIFO sin delay) | Reintentos con `release()`/backoff propio y scheduler para reprogramaciones largas |
| Postgres serverless escala a cero | Evitar conexiones persistentes ociosas; pool de conexiones; timeouts explícitos |
| Deploy con build + deploy commands (sin Docker propio) | Paridad con Docker local se garantiza por versión de PHP y extensiones, no por imagen |
| Scheduler: `schedule:run` cada minuto en cada réplica | Tareas con `onOneServer()` + `withoutOverlapping()` |
| Logs y métricas por entorno | Logs estructurados JSON con `tenant_id`, `request_id`, `run_id` |

## 6. Módulos (monolito modular)

```
app/
├── Domain/
│   ├── Shared/            # Money, Ruc, ClaveAcceso, Period, resultados tipados
│   ├── Tenancy/           # Tenant, Domain, contexto, middleware, traits
│   ├── Identity/          # User, Membership, Roles, invitaciones, 2FA
│   ├── Companies/         # Company, credenciales SRI, obligaciones
│   ├── Invoicing/         # Invoice, InvoiceItem, Taxes, parsers XML/TXT
│   ├── Sri/               # Gateway Lambda, SyncRun, orquestación, webhooks
│   ├── Classification/    # Reglas, catálogo, LLM, revisión humana
│   ├── Fiscal/            # Catálogos versionados, calculadoras, formularios
│   └── Reporting/         # PDF/Excel, exports, dashboards
├── Http/
│   ├── Controllers/Api/V1/
│   ├── Requests/          # FormRequest por endpoint
│   ├── Resources/         # API Resources (nunca modelos crudos)
│   ├── Middleware/
│   └── Webhooks/
├── Jobs/{Sri,Classification,Fiscal,Reports}/
├── Events/ Listeners/ Policies/ Providers/
└── Support/               # helpers sin estado (fechas, arrays, cifrado)

resources/js/
├── app.ts  router/  stores/  lib/  composables/
├── components/{ui,charts,tables,forms}/
├── layouts/  pages/
└── types/                 # generados desde OpenAPI

tests/{Unit,Feature,Contract,E2E,Fixtures,Arch}
docs/  docker/  database/{migrations,factories,seeders}
```

**Regla de dependencias:** `Http` → `Domain`; `Domain` no conoce HTTP. `Domain` puede depender de contratos (`SriGateway`, `Classifier`, `Clock`), nunca de SDKs concretos. Los controladores solo orquestan una Action.

### Flujo de una petición

```
Request → TenantContext [single: tenant por defecto | multi: ResolveTenant por subdominio] → EnsureTenantActive → auth:sanctum → Policy
        → FormRequest (validación) → Controller → Action → Domain
        → API Resource → JSON
```

## 7. Ingesta SRI (AWS Lambda)

### 7.1 Contrato

**Invocación asíncrona** (`InvocationType=Event`), con destino *on success/failure* hacia S3. El robot escribe los archivos en `s3://{bucket}/runs/{run_id}/` y un `manifest.json`; luego notifica por callback firmado.

```json
POST lambda:Invoke (Event)
{
  "run_id": "01J...ULID",
  "tenant_id": "01J...",
  "company_id": "01J...",
  "ruc": "0999999999001",
  "sealed_credentials": "base64(sodium_crypto_box_seal(json))",
  "date_from": "2026-08-01",
  "date_to": "2026-08-31",
  "document_types": ["01", "03", "04", "05", "07"],
  "output": { "bucket": "autotributo-prod-sri", "prefix": "runs/01J.../" },
  "callback_url": "https://app.autotributo.com/api/v1/webhooks/sri-robot"
}
```

Reglas:
- **Las credenciales del contribuyente nunca viajan en claro.** Se sellan con `sodium_crypto_box_seal` usando la clave pública del robot; la privada vive en AWS Secrets Manager y solo la Lambda la lee. Si el runtime no soporta libsodium, se usa envelope encryption con KMS.
- La Lambda no conoce la base de datos. Solo descarga, empaqueta y escribe en S3.
- El callback se firma con HMAC-SHA256 (`X-Autotributo-Signature: t=<ts>,v1=<hmac>`), tolerancia 300 s, deduplicación por `event_id`.
- Si el callback se pierde, un job de reconciliación (`ReconcileSriRuns`, cada 10 min) lista el prefijo S3 de runs pendientes y continúa el flujo. **La verdad está en S3, no en el callback.**
- Límite Lambda 15 min → un run cubre como máximo **93 días** por tipo de comprobante; el orquestador divide rangos mayores.
- Concurrencia por RUC = 1 y *rate limit* configurable (p. ej. 6 llamadas/min por empresa) para no disparar bloqueos del portal.

### 7.2 Cadena de jobs

```
StartSriSync (sync)
  └─> InvokeSriRobot (queue: sri)
        └─> HandleSriRobotCallback (webhook, valida HMAC)
              └─> ParseSriManifest (queue: sri, 1 job por run)
                    └─> ImportSriDocument (1 job por archivo/lote de 25)
                          ├─> PersistInvoice (upsert idempotente por access_key)
                          ├─> ClassifyInvoiceItems (queue: ai)
                          └─> RefreshPeriodAggregates (queue: fiscal)
                                └─> FiscalRiskScan
```

- Cada job guarda `run_id` y `tenant_id`; los logs los incluyen.
- `PersistInvoice` usa `upsert` sobre `(tenant_id, company_id, access_key)`; el reprocesamiento no duplica.
- Estados del run: `queued → invoking → downloading → parsing → completed | partial | failed | cancelled`. La UI se alimenta de `SriSyncRun` + eventos Reverb (best-effort) y **reconcilia con polling** cada 5 s mientras el run esté activo.
- Errores por archivo no abortan el run: se registran en `sri_sync_run_files` y el run termina `partial` con detalle.

### 7.3 Parser

- XML: `DOMDocument` con `LIBXML_NONET` (nunca `XXE`), sin `simplexml_load_string` sobre input no confiable sin `libxml_disable_entity_loader`.
- Validación de `access_key` (49 dígitos, módulo 11) y RUC (módulo 11 + reglas de tercer dígito).
- DTOs inmutables por tipo de comprobante; totales verificados contra la suma de ítems + impuestos (tolerancia 0,01 USD → se registra discrepancia, no se corrige silenciosamente).
- El XML original se guarda comprimido en S3 con `sha256`; la base guarda la referencia, no el archivo.

## 8. Clasificación con IA

```
InvoiceItem.description
   └─> normalización (mayúsculas, sin acentos, sin ruido)
        └─> RuleEngine (por tenant, ordenado por prioridad)
              ├─ match → categoría + confianza 1.0 (method=rule)
              └─ sin match → caché por hash(descripción normalizada)
                    ├─ hit → categoría (method=cache)
                    └─ miss → LLM (batch, JSON schema estricto)
                          └─ confianza >= umbral → auto (method=llm)
                          └─ confianza <  umbral → revisión humana
```

- **Proveedor abstraído** (`Classifier`): `FakeClassifier` (tests), `OpenAiClassifier`, `AnthropicClassifier`.
- Prompt versionado en `resources/prompts/classify-item.v1.md`; **la versión se guarda en cada clasificación**.
- Salida JSON validada estrictamente (enum de categorías + `confidence` 0..1); si el modelo devuelve algo inválido, se reintenta una vez y se degrada a revisión humana.
- **Presupuesto por tenant** y global (`llm_calls.cost_micros`); al superar el umbral se desactiva la clasificación automática y se encola revisión manual.
- Minimización de datos: solo se envía la descripción del ítem y el rubro del emisor; nunca RUC, nombres, montos ni credenciales.
- Evaluación offline con dataset dorado (≥300 descripciones etiquetadas) ejecutada semanalmente; métricas registradas en `docs/04`.

## 9. Motor fiscal

- **Catálogos versionados** por vigencia (`valid_from`, `valid_to`) y por año fiscal: tarifas de IVA, porcentajes de retención, casilleros de formularios, topes de gastos personales, canasta básica. Nada hardcodeado.
- **Cálculo determinista**: entra un `FiscalPeriod` + movimientos, sale un `DeclarationDraft` con líneas y desglose. Sin acceso a red, sin LLM, sin `now()` implícito (se inyecta `Clock`).
- **Trazabilidad**: cada línea del formulario guarda sus fuentes (`declaration_line_entries` → facturas/ítems), lo que habilita el drill-down y la auditoría.
- **Invariantes verificadas en tests y en runtime**:
  1. `sum(items.precio_total) + impuestos = invoice.total` (±0,01).
  2. `sum(bases por tarifa) = base imponible total del período`.
  3. `IVA ventas − IVA compras − retenciones + crédito anterior = IVA a pagar`.
  4. Una declaración cerrada es inmutable; cambios generan nueva versión (`supersedes_id`).
- **Proporcionalidad de IVA** (ventas 0 % vs. tarifa general): factor con 6 decimales; redondeo *half-up* solo al expresar el casillero, documentado y testeado con casos límite.
- **Retenciones**: se soportan retenciones de IVA e IR, con tasas y códigos desde catálogo; el 103 se alimenta de comprobantes de retención emitidos y recibidos según corresponda.
- **RIMPE**: el régimen determina obligaciones y formularios aplicables desde una tabla de configuración; no se asume en código.

## 10. Contratos

### API

- Base: `/api/v1`. Recursos: `companies`, `invoices`, `invoice-items`, `classification`, `sync-runs`, `declarations`, `reports`, `catalogos`.
- Autenticación: cookie de sesión Sanctum (SPA mismo origen) + `X-XSRF-TOKEN`; tokens Bearer para integraciones.
- Tenant: en el MVP single-tenant se usa el tenant por defecto (dominio único); en modo multi, subdominio. `X-Tenant` queda reservado para clientes API con token (nunca para sesiones de navegador).
- Paginación: cursor para `invoices` (`?cursor=`), offset para catálogos.
- Filtros declarativos: `filter[date_from]`, `filter[status]`, `filter[provider_ruc]`, `filter[category]`.
- Acciones largas: `POST` devuelve `202 { data: { run_id } }` + `Location`.
- Idempotencia: `Idempotency-Key` obligatorio en `POST /sync-runs` y `POST /declarations/{id}/close`.
- Errores: `application/problem+json` (RFC 9457) con `type`, `title`, `status`, `detail`, `errors[]`.
- Documentación: OpenAPI generada con **Scramble**; en CI se valida que el contrato no rompa tipos; el frontend consume tipos generados (`openapi-typescript`).

### Eventos (Reverb)

Canales privados: `private-tenant.{tenantId}.company.{companyId}` y `private-tenant.{tenantId}.user.{userId}`. Autorización en `routes/channels.php` verificando pertenencia. Eventos: `SriSyncProgressUpdated`, `InvoiceImported`, `ClassificationCompleted`, `FiscalRiskDetected`, `DeclarationDraftReady`. Los eventos son *notificaciones*, no fuente de verdad.

### Colas

| Cola | Contenido | Timeout | Reintentos |
|---|---|---|---|
| `sri` | invocación, parseo, importación | 120 s | 3, backoff exponencial |
| `ai` | clasificación por lote | 60 s | 2 |
| `fiscal` | recálculo de agregados, riesgos | 90 s | 2 |
| `reports` | PDF/Excel | 300 s (Pro) | 1 |
| `default` | notificaciones, correo | 30 s | 3 |

En local se usa `redis`; en Cloud `cloud` (SQS). **Ningún job debe asumir orden de ejecución ni entrega única.**

## 11. Seguridad y privacidad

- **Credenciales SRI**: cifradas con `SRI_CREDENTIALS_KEY` (clave dedicada, no `APP_KEY`), nunca logueadas, descifradas solo en memoria durante la invocación, rotación disponible, borrado al eliminar la empresa. Acceso auditado (`sri_credential_accesses`).
- **Cifrado**: TLS 1.2+ en tránsito; Postgres cifrado en reposo (Neon); S3 con SSE-S3/SSE-KMS y TLS obligatorio; backups cifrados.
- **Buckets por ambiente**: `autotributo-local-*`, `autotributo-test-*`, `autotributo-staging-*` y `autotributo-prod-*`. Nunca se comparten credenciales ni buckets entre entornos (ver `docs/aws-s3-setup.md`).
- **Auth**: contraseñas con Argon2id, 2FA TOTP opcional (obligatorio para `owner`), bloqueo por intentos, sesiones revocables, expiración.
  Se usa **Sanctum** (cookie de sesión para la SPA y tokens para integraciones) en lugar de JWT: permite revocación inmediata, cookies `HttpOnly` y no requiere gestionar refresh tokens. Ver ADR-0004.
- **Autorización**: Policies por recurso + roles (`owner`, `accountant`, `assistant`, `viewer`); scoping por empresa (`company_user`).
- **Auditoría**: `audit_logs` con actor, tenant, IP, user-agent, cambios antes/después. Retención ≥ 1 año.
- **LOPDP (Ecuador)**: base legal y consentimiento para tratar credenciales y datos del contribuyente; aviso de privacidad; derechos ARCO; exportación y borrado por tenant; registro de actividades de tratamiento; minimización.
- **OWASP ASVS L2** como checklist de Fase 6: CSP, HSTS, rate limiting, CSRF, validación de entrada, headers, dependencias.
- **Secretos**: solo en variables de Cloud/gestor de secretos; `gitleaks` en pre-commit y CI; rotación trimestral.
- **Webhooks**: HMAC + timestamp + deduplicación + límite de tamaño + verificación de `run_id` existente.

## 12. Observabilidad

- **Logs** JSON con `tenant_id`, `user_id`, `request_id`, `run_id`, `job_id`, `duration_ms`; sin PII ni credenciales.
- **Métricas** (Pulse + dashboard Cloud + Sentry): latencia p50/p95/p99, tasa de error, profundidad de cola, jobs fallidos, runs SRI por estado, coste LLM por tenant, comprobantes importados/minuto.
- **Alertas**: error rate > 2 % (5 min), cola `sri` > 500 mensajes, fallos SRI > 10 % en 1 h, presupuesto LLM > 80 %, backup fallido, certificado por vencer.
- **Trazas**: Sentry performance en endpoints críticos y jobs; sampling 10 % en producción.
- **SLOs MVP**: disponibilidad 99,5 % mensual; p95 API < 300 ms; sincronización de 5.000 comprobantes < 10 min; RPO ≤ 15 min; RTO ≤ 4 h.

## 13. Requisitos no funcionales

| Área | Requisito | Verificación |
|---|---|---|
| Rendimiento | p95 < 300 ms en listados de 50 filas; p99 < 800 ms | k6 + Sentry |
| Escala | MVP: 1 estudio; diseño preparado para 100 tenants, 1.000 usuarios y 1 M comprobantes/año con 2× holgura | pruebas de carga y EXPLAIN |
| Ingesta | 5.000 comprobantes en < 10 min con 3 workers | benchmark en staging |
| Precisión fiscal | 100 % de reconciliación en fixtures; 0 diferencias no explicadas | tests de invariantes |
| IA | ≥ 92 % accuracy de categoría; ≥ 95 % precisión en "deducible" | evaluación semanal |
| Seguridad | Sin hallazgos críticos/altos abiertos; ASVS L2 | auditoría + SAST |
| Accesibilidad | WCAG 2.1 AA en flujos principales | axe + revisión manual |
| i18n | Español (Ecuador), fechas `dd/mm/yyyy`, USD | tests de formato |
| Retención | Comprobantes y XML ≥ 7 años (normativa tributaria) | política de ciclo de vida S3 |

## 14. Reglas de oro

1. Si una consulta no filtra por tenant, es un bug de seguridad.
2. El dinero se guarda en centavos (`bigint`) y se opera con `brick/money`; nunca `float`.
3. Un job se puede ejecutar dos veces sin efectos duplicados.
4. Los jobs llevan IDs, no payloads.
5. Las reglas fiscales viven en catálogos versionados y se validan con un contador.
6. La IA nunca es la única fuente de una decisión fiscal: siempre hay revisión y auditoría.
7. Los secretos no tocan el repositorio, los logs ni las trazas.
8. Todo cambio de esquema es compatible con la versión anterior durante un deploy.
