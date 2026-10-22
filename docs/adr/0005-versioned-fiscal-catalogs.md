# ADR-0005: Catálogos fiscales versionados por vigencia

- **Estado:** aceptado
- **Fecha:** 2026-10-22
- **Decisores:** backend, contador asesor

## Contexto

Las reglas fiscales ecuatorianas cambian con frecuencia: tarifas de IVA (12 % → 15 % en 2024), porcentajes y códigos de retención, casilleros de formularios, topes de gastos personales y canasta básica. Si estos valores viven en constantes, cada reforma exige un despliegue de código, con riesgo de calcular períodos históricos con reglas nuevas.

Además, el sistema debe poder recalcular un período pasado con las reglas vigentes **en ese momento**, y explicar por qué un casillero tiene cierto valor.

## Decisión

1. Todo parámetro fiscal vive en **tablas de catálogo** con `valid_from` y `valid_to` (nullable = vigente).
2. Las entidades calculadas referencian el catálogo usado (`tax_rate_id`, `retention_rate_id`, `form_line_id`) y guardan un snapshot del cálculo.
3. Los catálogos se cargan por seeds/comandos desde fuentes oficiales, con `source`, `published_at` y `checksum`.
4. Las calculadoras reciben un `FiscalContext` (fecha, régimen, catálogos resueltos) y son **puras y deterministas**.
5. Un cambio de tarifa a mitad de período se aplica por fecha de emisión del comprobante, no por fecha de carga.
6. Los formularios se resuelven por `form_code` + año/período; los casilleros (`line_code`) también están versionados.
7. Nada de constantes fiscales en código. Si un valor no está en el catálogo, el cálculo falla con un error explícito, no asume un default.
8. Cada actualización requiere: PR con migración/seeder, tests golden, revisión del contador y registro en `docs/fiscal-signoff.md`.

## Consecuencias

**Positivas**
- Recalcular períodos históricos con fidelidad.
- Reformas sin tocar lógica: se agrega una fila con vigencia.
- Auditoría clara: cada línea del formulario puede rastrear la regla aplicada.
- Tests reproducibles con catálogos de fixture.

**Negativas**
- Más tablas y joins; hay que cachear los catálogos por período.
- Requiere disciplina de datos y validación del contador.
- Los seeds deben versionarse y probarse como código.

**Mitigaciones**
- Caché por `(form_code, fiscal_year, valid_from)` con invalidación al publicar catálogos.
- Comando `catalogs:validate` que detecta huecos, solapamientos y vigencias inválidas.
- Suite golden que falla si un cambio de catálogo altera un período cerrado.

## Alternativas consideradas

| Alternativa | Por qué no |
|---|---|
| Constantes en código | Cada reforma exige deploy; imposible recalcular historia con fidelidad |
| Configuración `.env`/`config` | Sin vigencia, sin auditoría, sin integridad referencial |
| Solo `fiscal_year` | Insuficiente: los cambios pueden ocurrir a mitad de año |
| Guardar solo el resultado calculado | Se pierde la trazabilidad y la posibilidad de recalcular |

## Proceso anual de actualización

1. Publicación oficial del SRI / INEC → tarea con fecha límite.
2. Importar catálogos con `valid_from` del período correspondiente.
3. Ejecutar `catalogs:validate` y la suite golden.
4. Revisión y firma del contador.
5. Desplegar con feature flag por año fiscal y verificar en staging con un período de prueba.
