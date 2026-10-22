# 08 — Backlog MVP (historias y criterios de aceptación)

> Formato: `US-xx`. Las historias marcadas **Must** son requisito de lanzamiento. Cada una incluye fase, tamaño (`S` ≤ 1 d, `M` 2–3 d, `L` 4–5 d) y pruebas obligatorias.

## Épica 1 — Identidad y acceso

### US-01 · Registro de estudio y onboarding · Must · F1 · M
**Como** contador independiente **quiero** crear mi estudio y mi primera empresa **para** empezar a operar el mismo día.

```gherkin
Escenario: Registro exitoso
  Dado que no existe una cuenta con "ana@estudio.ec"
  Cuando me registro con nombre, email y contraseña válida
  Entonces se crea un tenant en estado "trial" con subdominio único
  Y recibo un email de verificación
  Y se cargan los catálogos fiscales vigentes

Escenario: Subdominio ocupado
  Dado que existe el tenant "estudio-ana"
  Cuando intento registrarme con el mismo slug
  Entonces la API responde 422 con el campo "slug"
  Y el tenant no se crea

Escenario: Contraseña débil
  Cuando me registro con una contraseña de menos de 12 caracteres
  Entonces la API responde 422 y no se crea ningún registro
```

**Pruebas:** feature (registro, duplicados, validación, email), unit (slug, política de contraseña), E2E (onboarding).

---

### US-02 · Invitar miembros con rol · Must · F1 · S
**Como** dueño del estudio **quiero** invitar contadores con roles **para** delegar trabajo sin exponer todo.

- Invitación por email con token de un solo uso, expiración 72 h.
- Roles: `owner`, `accountant`, `assistant`, `viewer`.
- No se puede invitar a un email ya activo en el tenant (409).
- Un `assistant` no puede invitar ni aprobar declaraciones.
- Revocar acceso invalida sesiones activas del usuario en ese tenant.

**Pruebas:** feature (invitación, aceptación, expiración, reuso, permisos), unit (matriz rol×acción), E2E (flujo de invitación).

---

### US-03 · 2FA obligatorio para owner · Must · F1 · S
```gherkin
Escenario: Owner sin 2FA
  Dado un usuario con rol owner sin 2FA confirmado
  Cuando inicia sesión
  Entonces recibe un token de alcance limitado que solo permite configurar 2FA
  Y no puede acceder a endpoints de negocio

Escenario: Código inválido
  Cuando ingresa un código TOTP incorrecto 5 veces
  Entonces la cuenta se bloquea por 15 minutos y se registra el evento
```

**Pruebas:** feature (TOTP válido/inválido/expirado, recovery codes, bloqueo), unit (ventana de tiempo).

---

### US-04 · Cambiar de empresa y de estudio · Must · F1 · S
- Selector de empresa visible en el header; la selección persiste por usuario/tenant.
- Cambiar de empresa **no** cambia el tenant; el backend valida pertenencia.
- Si el usuario pertenece a más de un estudio, puede cambiar de tenant y se refresca la sesión.
- Acceso a empresa ajena → 404.

**Pruebas:** feature (scoping, persistencia, 404), E2E (cambio y recarga).

---

### US-05 · Seguridad de la cuenta · Should · F1 · S
- Cambio de contraseña con verificación de la actual y cierre del resto de sesiones.
- Listado y revocación de sesiones activas.
- Historial de inicios de sesión con IP y user-agent.

**Pruebas:** feature (revocación, contraseña), unit (política).

---

## Épica 2 — Empresas y credenciales

### US-06 · Registrar contribuyente · Must · F1 · M
```gherkin
Escenario: RUC válido
  Cuando registro la empresa con RUC "0999999999001"
  Entonces se valida el dígito verificador y el tipo de contribuyente
  Y la empresa queda activa en el tenant actual

Escenario: RUC de otro tenant
  Dado que el RUC ya existe en otro estudio
  Cuando lo registro en el mío
  Entonces se permite (son carteras independientes)
  Y no se filtran datos del otro tenant

Escenario: RUC inválido
  Cuando registro un RUC con dígito verificador incorrecto
  Entonces la API responde 422 y no se crea la empresa
```

**Pruebas:** unit (`Ruc` con tabla de casos), feature (creación, duplicado en el mismo tenant, aislamiento), E2E (formulario).

---

### US-07 · Perfil tributario y obligaciones · Must · F1 · M
- Régimen (`general`, `rimpe_emprendedor`, `rimpe_negocio_popular`, `persona_natural`, `sociedad`).
- Flags: obligado a llevar contabilidad, agente de retención, resolución.
- Las obligaciones (`104`, `103`, `102/101`, `ATS`) se derivan del catálogo y son editables con justificación.
- Cambiar de régimen recalcula obligaciones futuras, no históricas.

**Pruebas:** unit (derivación por régimen/año), feature (edición, historial, recálculo).

---

### US-08 · Credenciales SRI cifradas · Must · F1 · M
```gherkin
Escenario: Guardar credencial
  Cuando guardo usuario y clave del SRI
  Entonces la clave se almacena cifrada con la clave dedicada
  Y nunca aparece en respuestas, logs ni auditoría
  Y se registra el acceso en sri_credential_accesses

Escenario: Rotación
  Cuando actualizo la clave
  Entonces se incrementa key_version y se conserva la anterior hasta migrar
  Y el siguiente run usa la nueva
```

**Pruebas:** feature (guardar/leer/rotar, redacción, auditoría), unit (cifrado/descifrado, versión), seguridad (grep de logs).

---

### US-09 · Archivar empresa · Should · F2 · S
- Archivar exige confirmar y escribir el RUC.
- Revoca y borra credenciales SRI inmediatamente.
- Detiene sincronizaciones programadas; conserva comprobantes y declaraciones históricas.
- Auditoría del actor, fecha y motivo.

**Pruebas:** feature (borrado de credencial, bloqueo de sync, datos históricos intactos).

---

## Épica 3 — Ingesta SRI

### US-10 · Iniciar sincronización · Must · F2 · M
```gherkin
Escenario: Rango válido
  Dado una empresa con credenciales activas
  Cuando solicito sincronizar del 2026-08-01 al 2026-08-31
  Entonces se crea un run "queued" con idempotency key única
  Y se encola la invocación del robot
  Y la API responde 202 con el run_id

Escenario: Run en curso
  Dado un run activo para la misma empresa
  Cuando solicito otro run
  Entonces la API responde 409 "sync_already_running"

Escenario: Rango mayor a 93 días
  Cuando solicito 6 meses
  Entonces se crean ventanas de máximo 93 días encadenadas
  Y el progreso refleja el total de ventanas
```

**Pruebas:** feature (202/409, idempotencia, división de rangos, validación de fechas), contract (payload Lambda), unit (cálculo de ventanas).

---

### US-11 · Progreso en tiempo real · Must · F2 · M
- Estados visibles: `queued`, `invoking`, `downloading`, `parsing`, `completed`, `partial`, `failed`, `cancelled`.
- Eventos Reverb + polling de respaldo cada 5 s mientras el run esté activo.
- El progreso muestra documentos encontrados/importados/omitidos/fallidos.
- Reconexión del WebSocket sin perder el estado.

**Pruebas:** feature (endpoint de estado, autorización de canal), E2E (progreso simulado), unit (máquina de estados).

---

### US-12 · Importación idempotente · Must · F2 · M
```gherkin
Escenario: Reprocesar el mismo paquete
  Dado un run completado con 120 comprobantes
  Cuando se vuelve a procesar el mismo prefijo S3
  Entonces el conteo de facturas no cambia
  Y los ítems no se duplican
  Y el run queda "completed" con 120 omitidos

Escenario: Nota de crédito
  Cuando se importa una NC que referencia una factura
  Entonces se crea la relación y el período refleja el ajuste
```

**Pruebas:** feature (idempotencia, NC, retenciones, duplicado por clave), unit (parser por tipo), integración (5k documentos).

---

### US-13 · Fallos por archivo y reintento · Must · F2 · S
- Cada archivo fallido guarda motivo y `s3_key`.
- El run termina `partial` si hay fallos y `failed` si no importó nada.
- Reintento puntual idempotente; nunca reprocesa lo ya importado.

**Pruebas:** feature (archivo corrupto, reintento, estado partial), unit (clasificación de errores).

---

### US-14 · Carga manual de XML (contingencia) · Must · F2 · M
```gherkin
Escenario: Subir XML válido
  Cuando subo un XML autorizado de factura
  Entonces se valida estructura, clave y totales
  Y se importa con source "manual_upload"
  Y queda disponible para clasificación y declaración

Escenario: XML alterado
  Cuando el total no coincide con la suma de ítems
  Entonces se importa marcado con un hallazgo crítico
  Y no se suma silenciosamente al período
```

**Pruebas:** unit (validación), feature (upload, límite de tamaño, duplicado, cuarentena), seguridad (XXE, zip bomb).

---

### US-15 · Reconciliación de runs huérfanos · Should · F2 · S
- Job programado cada 10 min detecta runs en `invoking/downloading` con más de 30 min.
- Verifica el prefijo S3 y reanuda o marca `failed` con causa.
- Alerta si hay más de 3 runs huérfanos seguidos.

**Pruebas:** feature (run huérfano con/sin archivos), unit (timeout), integración scheduler.

---

## Épica 4 — Clasificación y riesgos

### US-16 · Reglas de clasificación · Must · F3 · M
```gherkin
Escenario: Regla por proveedor
  Dado una regla activa para el RUC "0999999999001" → "Alimentación"
  Cuando se importa un ítem de ese proveedor
  Entonces se clasifica con method "rule" y confianza 1.0
  Y no se consume presupuesto de IA

Escenario: Prioridad
  Dado dos reglas que coinciden con distinta prioridad
  Cuando se clasifica el ítem
  Entonces gana la de menor número de prioridad
```

**Pruebas:** unit (match, precedencia, regex inválida), feature (CRUD, aislamiento, dry-run), integración (cache invalidation).

---

### US-17 · Clasificación con IA · Must · F3 · L
- Se aplica solo si no hay regla ni caché.
- Salida JSON validada: categoría del enum + confianza 0..1 + razón breve.
- `confidence >= LLM_CONFIDENCE_THRESHOLD` → `auto`; si no → `needs_review`.
- Timeout, 429 y respuesta inválida → `needs_review`, nunca error al usuario.
- Cada llamada registra modelo, prompt, tokens y coste; se respeta el presupuesto.
- Datos enviados: solo descripción normalizada y rubro del emisor.

**Pruebas:** unit (validación de salida, umbral), contract (respuestas grabadas, timeout, 429), feature (presupuesto, caché, degradación), evaluación offline.

---

### US-18 · Revisión humana de clasificaciones · Must · F3 · M
- Cola filtrable por empresa, período, proveedor, categoría y confianza.
- Aceptar, cambiar categoría o rechazar; acciones masivas.
- Cada cambio deja auditoría y `classification_feedback`.
- Atajos de teclado y contador de pendientes.

**Pruebas:** feature (acciones, masivo, permisos, auditoría), unit (filtros), E2E (revisión en bloque).

---

### US-19 · Crear regla desde un ítem · Should · F3 · S
- Sugiere patrón a partir de la descripción y del proveedor.
- Vista previa del impacto ("afectará a 37 ítems de este proveedor") antes de guardar.
- No crea reglas que colisionen con una existente de igual prioridad sin confirmación.

**Pruebas:** feature (creación, preview, conflicto), unit (generación de patrón).

---

### US-20 · Alertas de riesgo fiscal · Must · F3 · M
- Reglas: comprobante duplicado, totales inconsistentes, proveedor en lista de empresas fantasma, retención faltante, comprobante fuera de período, tope de gasto excedido, RUC inválido.
- Severidad `info/warning/critical`; acción sugerida y enlace al comprobante.
- Sin duplicados de hallazgos abiertos; se pueden reconocer o resolver con motivo.
- Un hallazgo `critical` bloquea el cierre de la declaración.

**Pruebas:** unit (cada regla positiva/negativa), feature (dedupe, bloqueo, resolución), integración (importación dispara escaneo).

---

### US-21 · Presupuesto de IA por tenant · Should · F3 · S
- Límite mensual configurable; al 80 % se notifica; al 100 % se detiene la clasificación automática.
- El cálculo usa `llm_calls.cost_micros` del mes.
- El administrador puede elevar el límite con auditoría.

**Pruebas:** feature (límite, notificación, corte, override), unit (agregación de coste).

---

## Épica 5 — Declaraciones e informes

### US-22 · Borrador del Formulario 104 · Must · F5 · L
```gherkin
Escenario: Ventas y compras del período
  Dado un período con ventas al 15 %, ventas al 0 % y compras con IVA
  Cuando genero el borrador 104
  Entonces cada casillero se calcula desde el catálogo vigente
  Y el total de IVA a pagar coincide con la suma de bases e impuestos
  Y cada línea permite ver sus comprobantes de origen

Escenario: Crédito del período anterior
  Dado un período anterior con crédito tributario
  Cuando genero el borrador del mes siguiente
  Entonces el crédito se arrastra y se muestra su origen

Escenario: Diferencias
  Dado un comprobante con totales inconsistentes
  Cuando intento cerrar la declaración
  Entonces el cierre se bloquea con el detalle de las diferencias
```

**Pruebas:** unit (tabla de escenarios), feature (generación, recálculo, bloqueo), golden files, E2E (drill-down).

---

### US-23 · Drill-down de casilleros · Must · F5 · S
- Cada línea abre el listado de comprobantes/ítems que la componen.
- Totales de la lista coinciden con la línea (invariante).
- Exportable a Excel con las mismas columnas.

**Pruebas:** feature (consistencia de sumas, permisos), unit (redondeo), E2E.

---

### US-24 · Ciclo de vida y versionado · Must · F5 · M
- `draft → in_review → approved → filed`; solo `owner/accountant` aprueba.
- Aprobada/filed es inmutable; cambios generan versión n+1 con `supersedes_id`.
- Motivo obligatorio para nueva versión; historial visible.
- Snapshot y checksum guardados para auditoría.

**Pruebas:** feature (transiciones válidas/inválidas, inmutabilidad, versionado, permisos), unit (checksum), E2E (aprobar).

---

### US-25 · Formulario 103 · Must · F5 · M
- Consolida retenciones de IR e IVA por código del período.
- Cruza con comprobantes de retención; alerta si falta una retención esperada.
- Exportable en PDF/Excel con el desglose por proveedor.

**Pruebas:** unit (tabla de retenciones), feature (consolidación, faltantes), golden file.

---

### US-26 · Formulario 102/101 · Should · F5 · L
- Gastos personales por categoría con topes anuales del catálogo.
- Proyección anual y advertencia de exceso.
- Ingresos y deducciones desde comprobantes; ajustes manuales auditados.
- Formulario aplicable según régimen y obligación de llevar contabilidad.

**Pruebas:** unit (topes, proyección, régimen), feature (generación, ajustes, permisos), golden file.

---

### US-27 · Exportar PDF y Excel · Must · F5 · M
- PDF con logo del estudio, período, RUC, casilleros y firma de responsable.
- Excel con hojas: resumen, detalle por casillero, comprobantes.
- Generación en cola `reports`; descarga por URL firmada con expiración.
- Nombre de archivo predecible: `104-{RUC}-{YYYY-MM}-v{n}.pdf`.

**Pruebas:** feature (job, URL firmada, permisos, expiración), unit (nombre), snapshot de texto del PDF.

---

## Épica 6 — Operación y confianza

### US-28 · Auditoría visible por el estudio · Should · F6 · S
- Listado filtrable de acciones sensibles (accesos a credenciales, aprobaciones, exportaciones, cambios de rol).
- Solo `owner` puede ver auditoría completa; `accountant` ve la de sus empresas.
- Exportable para revisiones internas.

**Pruebas:** feature (filtros, permisos, export), unit (redacción de datos sensibles).

---

### US-29 · Exportación y offboarding del tenant · Must · F6 · M
- Exportación completa (JSON/CSV + XML) solicitada por `owner`, generada en cola y entregada por URL firmada.
- Eliminación con confirmación en dos pasos, período de gracia de 30 días y anonimización.
- Revocación de credenciales y borrado de objetos S3; constancia de borrado.
- Cumple derechos de acceso y supresión (LOPDP).

**Pruebas:** feature (export completo, borrado, período de gracia, constancia), seguridad (no accesible por otro tenant).

---

## Priorización

| Prioridad | Historias |
|---|---|
| **Must** | US-01, 02, 03, 04, 06, 07, 08, 10, 11, 12, 13, 14, 16, 17, 18, 20, 22, 23, 24, 25, 27, 29 |
| **Should** | US-05, 09, 15, 19, 21, 26, 28 |
| **Could** | ATS completo, branding por tenant, plantillas de reportes, API pública documentada |
| **Won't (MVP)** | Conciliación bancaria, emisión de comprobantes, OCR, app móvil |

## Trazabilidad historia → fase → pruebas

| Fase | Historias | Suites |
|---|---|---|
| F1 | US-01…US-09 | Unit (RUC, auth), Feature (isolation, policies), E2E (onboarding) |
| F2 | US-10…US-15 | Unit (parser), Feature (sync), Contract (Lambda/S3), E2E (monitor) |
| F3 | US-16…US-21 | Unit (reglas, cálculo), Contract (LLM), Feature (revisión, riesgos), eval IA |
| F4 | Todas (UI) | Vitest, Playwright, axe, Lighthouse |
| F5 | US-22…US-27 | Unit (calculadoras), Feature (ciclo de vida), Golden files, E2E (export) |
| F6 | US-28, US-29 | Feature (auditoría, offboarding), seguridad, DR |
