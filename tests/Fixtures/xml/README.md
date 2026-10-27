# Fixtures XML

Documentos **sintéticos** (RUC, nombres y montos inventados) que reproducen la
estructura de los comprobantes que devuelve el SRI por clave de acceso.
Ninguno proviene de un documento real.

| Archivo | Tipo | Caso que cubre |
|---|---|---|
| `invoice/factura.xml` | `01` Factura | Dos líneas (tarifa 15 % y 0 %), descuento por línea, descuento global, acentos en la descripción, propina en cero |
| `credit_note/nota-credito.xml` | `04` Nota de crédito | Ajuste de una factura: `infoNotaModificada` con `numDocModificado` y un solo ítem |
| `debit_note/nota-debito.xml` | `05` Nota de débito | Misma estructura de modificación, importe de cargo |

## Marcadores

- `{{CLAVE}}`: clave de acceso del comprobante. El test la sustituye por una
  clave válida generada con `Tests\Support\Sri\AccessKeyFactory` (período
  `2026-09`, RUC `1758871873001`), porque el parser valida la estructura y el
  dígito verificador de la clave.
- `{{CLAVE_ORIGEN}}`: clave del documento modificado (solo notas de crédito y
  débito).

## Reglas

- Los montos deben cuadrar entre cabecera, ítems e impuestos
  (`total = subtotal − descuento + impuestos`). Un fixture descuadrado debe
  marcarse aquí explícitamente y probarse en un caso propio.
- Al agregar un tipo de comprobante, añadir su carpeta, esta fila en la tabla y
  un test en `tests/Unit/Sri/SriXmlParserTest.php`.
- **Prohibido** versionar XML con datos reales.
