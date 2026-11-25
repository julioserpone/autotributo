# Fiscal sign-off

Registro de validación de cálculos, catálogos y formularios por parte del contador asesor. **Ninguna fase fiscal se cierra sin firma aquí.**

## Reglas

- Cada cambio en catálogos (tarifas, casilleros, retenciones, topes) requiere una entrada.
- La validación se hace contra la ficha técnica vigente del SRI y una hoja de cálculo independiente.
- Se registra el escenario, el resultado del sistema, el resultado manual y la diferencia (debe ser 0,00).
- Un período ya declarado no se altera; cualquier corrección genera una nueva versión de la declaración.

## Registro

| Fecha | Versión | Módulo | Escenario | Resultado sistema | Resultado manual | Diferencia | Fuente oficial | Validado por |
|---|---|---|---|---|---|---|---|---|
| 2026-10-22 | v0 (pendiente) | Formulario 104 | Ventas 15 % + compras con crédito | — | — | — | Ficha técnica SRI (pendiente de adjuntar) | — |
| 2026-10-22 | v0 (pendiente) | Formulario 103 | Retenciones IR e IVA | — | — | — | Ficha técnica SRI (pendiente de adjuntar) | — |
| 2026-10-22 | v0 (pendiente) | Formulario 102 | Topes de gastos personales | — | — | — | Tabla anual (pendiente de adjuntar) | — |
| 2026-10-22 | v0 (pendiente) | Tarifas IVA | 0 %, 5 %, 12 % histórico, 15 % | — | — | — | Resolución SRI (pendiente de adjuntar) | — |
| 2026-11-13 | v1 (pendiente) | Catálogos F3-01 | IVA 0/5/12/14/15 %, retenciones IR/IVA 2026, casilleros base 104 y topes 2026 | `catalogs:validate` OK (50 filas) | — | — | SRI/INEC; detalle por fila en `FiscalCatalogData` | — |
| 2026-11-14 | v1 (pendiente) | Categorías F3-02 | Árbol de 9 categorías y banderas `is_personal_deductible`/`is_business_deductible` | 9 categorías cargadas de forma idempotente | — | — | Criterio interno; falta contraste con ficha del SRI | — |
| 2026-11-15 | v1 (pendiente) | Proporcionalidad F3-07 | Factor de proporcionalidad IVA: ventas 0 % / ventas totales, 6 decimales HALF_UP | `factor(100,00; 300,00) = 0.250000`; `0 %` y `15 %` puros = `1.000000` y `0.000000` | — | — | Ficha técnica del 104 (pendiente de adjuntar) | — |
| 2026-11-20 | v1 (pendiente) | Riesgos F3-06 | Códigos y severidades: `TOTALS_MISMATCH` (critical), `GHOST_COMPANY` (critical), `INVALID_RUC` (warning), `DUPLICATE_SEQUENTIAL` (warning); `OUT_OF_PERIOD`, `MISSING_RETENTION` y `EXPENSE_CAP_EXCEEDED` reservados | 4 detectores activos, auto-resolución solo de riesgos técnicos objetivos, 14 tests | — | — | Criterio interno; falta contraste con ficha del SRI | — |
| 2026-11-25 | v2 (pendiente) | Casilleros del 104 (F4-09) | Reemplazo del catálogo `form_lines` del 104: los 15 casilleros anteriores no correspondían al formulario oficial (402 estaba como «tarifa 5 %» y los códigos 401/402/411/412/421/422 se habían dado de alta como vigentes desde 2024-04-01). Se sustituyen por los **88 casilleros del formulario vigente**, con etiquetas tomadas del XLS oficial (Resolución NAC-DGERCGC16-00000210) | `Form104OfficialForm` reproduce las 6 secciones del XLS (ventas 401-441, liquidación 480-499, adquisiciones 500-564, resumen impositivo 601-699, retención 721-801, pago 859-999) y `catalogs:validate` carga 88 filas | — | — | Resolución NAC-DGERCGC16-00000210 (XLS «Formulario 104 2016», adjunto en `tests/`). Falta confirmar las etiquetas y el mapeo de campos | — |

## Escenarios mínimos a validar (Fase 5)

1. Ventas al 15 % únicamente.
2. Ventas 0 % y 15 % con proporcionalidad.
3. Compras con IVA y crédito tributario.
4. Nota de crédito que ajusta una factura del período.
5. Retenciones de IVA e IR.
6. Arrastre de crédito del período anterior.
7. Período sin movimientos.
8. Cambio de tarifa a mitad de período.
9. Contribuyente RIMPE.
10. Exceso de topes de gastos personales.
11. Comprobante con totales inconsistentes (debe bloquear el cierre).
12. Exportación PDF/Excel con cifras idénticas al borrador.

## Firma

| Rol | Nombre | Fecha | Firma / aprobación |
|---|---|---|---|
| Contador asesor | | | |
| Responsable técnico | | | |
| Product owner | | | |

## Historial de cambios

| Fecha | Cambio | Autor |
|---|---|---|
| 2026-10-22 | Creación de la plantilla | — |
| 2026-11-13 | Alta de catálogos F3-01 (IVA, retenciones, casilleros 104 y topes 2026); queda pendiente la firma del contador | — |
| 2026-11-14 | Alta de categorías de gasto F3-02 y banderas de deducibilidad; queda pendiente la firma del contador | — |
| 2026-11-15 | Alta de la calculadora de proporcionalidad F3-07 (factor 6 decimales HALF_UP); queda pendiente la firma de la convención que aplica al 104 | — |
| 2026-11-20 | Alta de los códigos y severidades de riesgo de F3-06; quedan reservados `OUT_OF_PERIOD`, `MISSING_RETENTION` y `EXPENSE_CAP_EXCEEDED` hasta la firma de la convención y F5 | — |
| 2026-11-21 | Revisión de sincronización: se confirma que **no hay ninguna firma registrada**; F3 no puede cerrarse formalmente y F5-02 no debe empezar sin la convención del 104 | — |
| 2026-11-25 | Sustitución del catálogo de casilleros del 104 (F4-09) por los 88 campos del formulario oficial vigente; queda pendiente la firma de las etiquetas y del mapeo campo → cálculo | — |
