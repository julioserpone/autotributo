# 02 — Plan de implementación

> **Cómo usar este documento:** cada fase tiene objetivo, tareas con ID (`F1-03`), criterios de aceptación verificables y pruebas obligatorias. Una fase no se cierra si sus pruebas no están en verde y el criterio de salida no está demostrado en *staging*. Los agentes de IA deben ejecutar una tarea por PR y no avanzar de fase sin aprobación humana.

## 0. Supuestos

| Supuesto | Valor |
|---|---|
| Equipo | 1 backend senior + 1 frontend (a tiempo parcial) + contador asesor (2–4 h/semana) |
| Duración MVP | **16–18 semanas** con 2 personas; **20–23 semanas** con 1 persona |
| Modalidad | Sprints de 2 semanas, trunk-based, PRs pequeños |
| Entorno | Docker local; Laravel Cloud staging + production desde Fase 1 |
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
| F1-03 | Identidad | 2 d | `users` global + `memberships` por tenant, invitaciones, reset, verificación de email, 2FA TOTP, Argon2id, sesiones revocables |
| F1-04 | Autorización | 1 d | Roles `owner/accountant/assistant/viewer` (spatie teams), policies por recurso, scoping por empresa |
| F1-05 | Empresas | 1,5 d | CRUD, validador de RUC, perfil tributario (régimen, obligado a contabilidad, agente de retención), actividad económica, dirección |
| F1-06 | Credenciales SRI | 1 d | Cifrado con `SRI_CREDENTIALS_KEY`, acceso auditado, rotación, borrado al eliminar empresa, nunca en logs |
| F1-07 | Auditoría base | 0,5 d | `audit_logs` + `owen-it/laravel-auditing` en modelos sensibles |
| F1-08 | Reverb | 0,5 d | Config, canales privados por tenant/empresa, autorización de canales, estado de conexión |
| F1-09 | Convenciones de API | 1 d | `problem+json`, paginación, filtros, resources, generación OpenAPI (Scramble) |
| F1-10 | Provisión inicial | 0,5 d | Comando idempotente `app:provision` (tenant por defecto, catálogos, owner e invitación). La primera empresa se crea desde la UI (US-06). Sin registro público en el MVP |
| F1-11 | Deploy a staging | 0,5 d | Recursos Cloud, variables, build/deploy commands, migraciones, dominio, scheduler |

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
| F2-02 | Layout S3 del robot | 1 d | `runs/{run_id}/{manifest.json, documents/*.xml.gz}`; checksums; ciclo de vida; bucket separado para el robot |
| F2-03 | `SriSyncRun` y orquestación | 2 d | Máquina de estados, `StartSriSync`, división de rangos > 93 días, `Idempotency-Key`, cancelación |
| F2-04 | Callback firmado | 1 d | Endpoint `POST /webhooks/sri-robot`, HMAC + timestamp + dedupe `event_id`, límite de tamaño, 202 |
| F2-05 | Reconciliación | 1 d | `ReconcileSriRuns` cada 10 min: lista prefijos pendientes en S3 y reanuda |
| F2-06 | Parser XML | 3 d | Factura, NC, ND, liquidación, retención; DTOs; validación de totales; XXE-safe; errores por documento |
| F2-07 | Parser de manifiesto/TXT | 1 d | Índice del robot, rutas, codificación, archivos corruptos |
| F2-08 | Persistencia idempotente | 2 d | `upsert` por `access_key`; ítems, impuestos, retenciones; deduplicación; `source` |
| F2-09 | Progreso en vivo | 1 d | Eventos Reverb + endpoint de estado; polling de respaldo |
| F2-10 | Resiliencia | 1,5 d | Reintentos con backoff, fallos parciales, archivos inválidos, timeouts, run `partial` |
| F2-11 | Límites y cuotas | 0,5 d | Concurrencia 1 por RUC, rate limit configurable, bloqueo de runs solapados |
| F2-12 | Observabilidad | 0,5 d | Logs con `run_id`, métricas de duración/volumen, alerta de fallos |

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
| F3-01 | Catálogos versionados | 2 d | `tax_rates`, `form_lines`, `retention_rates`, `personal_expense_caps` con `valid_from/valid_to`; seeds por año |
| F3-02 | Motor de reglas | 2 d | Reglas por tenant/globales, prioridad, match por descripción/proveedor/código, dry-run, detección de conflictos, cache con invalidación |
| F3-03 | Clasificador IA | 2 d | Contrato `Classifier`, fake, adaptador OpenAI/Anthropic, JSON schema, validación, 1 reintento, timeout, circuit breaker |
| F3-04 | Gobierno de IA | 1,5 d | Prompt versionado, caché por hash, `llm_calls` con tokens/coste/latencia, presupuesto por tenant, degradación a revisión manual |
| F3-05 | Revisión humana | 1,5 d | Cola de revisión, aceptar/rechazar/cambiar categoría, acciones masivas, auditoría de cambios |
| F3-06 | Riesgos fiscales | 2,5 d | Empresas fantasma (lista SRI), duplicados, totales inconsistentes, comprobantes fuera de período, retenciones faltantes, topes de gastos, proveedor recurrente sin RUC válido |
| F3-07 | Proporcionalidad IVA | 1 d | Factor 6 decimales, reglas de redondeo, casos 0 %/15 %, test de tabla |
| F3-08 | Evaluación offline | 1 d | Dataset dorado, script `php artisan ai:evaluate`, reporte de accuracy/precisión/coste, ejecución semanal programada |
| F3-09 | Administración de catálogos | 0,5 d | Endpoints de solo lectura + comando de importación desde CSV oficial |

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
| F4-01 | Fundaciones | 1 d | Vue 3 + TS strict, Vite, Tailwind 4, shadcn-vue, router, Pinia, TanStack Query, cliente HTTP con CSRF/errores, i18n |
| F4-02 | Design system | 2 d | Tokens (`#09090b`, `#18181b`, `#27272a`, borde `#262626`, acentos violeta/rojo/verde), tipografía, dark mode, Button/Input/Select/Combobox/DateRange/Table/Badge/Modal/Toast/Drawer |
| F4-03 | Auth y primer acceso | 1,5 d | Login, 2FA, invitación, recuperación, primer contribuyente (el estudio ya está provisionado) |
| F4-04 | Layout | 1 d | Sidebar colapsable, selector empresa/RUC, header con estado Reverb, breadcrumbs, atajos |
| F4-05 | Bandeja de comprobantes | 3 d | DataTable server-side, filtros (fecha, estado, proveedor, categoría, tipo), columnas configurables, drawer de detalle, acciones masivas |
| F4-06 | Monitor de sincronización | 2 d | Iniciar sync, progreso en vivo, historial, errores por archivo, reintentar, cancelar, polling de respaldo |
| F4-07 | Revisión de clasificación | 2 d | Cola con teclado (j/k/Enter), confianza visual, aceptar en bloque, crear regla desde un ítem |
| F4-08 | Dashboards | 1,5 d | IVA ventas vs. compras por mes, top proveedores, topes de gastos personales, alertas |
| F4-09 | Vista 104 (estructura) | 1,5 d | Casilleros, totales, drill-down a facturas, estados de borrador (datos reales llegan en F5) |
| F4-10 | Calidad de UI | 1 d | Estados vacíos/carga/error, responsive, foco visible, contraste AA, `prefers-reduced-motion` |
| F4-11 | Pruebas frontend | 1,5 d | Vitest + MSW para componentes/composables, Playwright para 5 flujos críticos |

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

| Sprint | Semanas | Foco | Demo |
|---|---|---|---|
| S0 | 1 | F0 | Decisiones, spike Lambda, catálogo v0 |
| S1 | 2 | F1 (a) | Tenant-ready + auth + empresas |
| S2 | 3–4 | F1 (b) + deploy | Staging con login y empresa |
| S3 | 5–6 | F2 (a) | Invocación + callback + parser factura |
| S4 | 7–8 | F2 (b) + inicio F3 | Sync completo idempotente |
| S5 | 9–10 | F3 | Clasificación + riesgos + revisión |
| S6 | 11–12 | F4 (a) | Layout, bandeja, monitor |
| S7 | 13–14 | F4 (b) | Revisión, dashboards, E2E |
| S8 | 15–16 | F5 (a) | 104 con drill-down |
| S9 | 17–18 | F5 (b) | 103/102 + exports + firma contador |
| S10 | 19–20 | F6 | Beta, hardening, lanzamiento |

Con 2 personas, F4 corre en paralelo desde S4 y el total baja a **≈16 semanas**.

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
| Particionar `invoices` desde el día 1 | Sin particionado en el MVP | Complejidad prematura: índices + agregados materializados cubren 1 M docs/año |
| Horizon para colas | Colas administradas + monitoreo Cloud | Horizon no es compatible con las colas gestionadas de Laravel Cloud |
| Descarga SRI dentro de Laravel | Lambda + S3 + callback firmado | Aislamiento del scraping, límites de 15 min y credenciales fuera de la app |
| Sin plan de pruebas | Pirámide completa + golden files + aislamiento | La exactitud fiscal y la confidencialidad son requisitos de producto, no "calidad extra" |
