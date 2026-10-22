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
