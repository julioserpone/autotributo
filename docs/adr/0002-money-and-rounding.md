# ADR-0002: Dinero en centavos y redondeo explícito

- **Estado:** aceptado
- **Fecha:** 2026-10-22
- **Decisores:** backend, contador asesor

## Contexto

Las declaraciones fiscales deben cuadrar al centavo. Los XML del SRI traen importes con dos decimales y precios unitarios con hasta seis. PHP usa `float` por defecto, lo que introduce errores de representación (`0.1 + 0.2 !== 0.3`). Un redondeo inconsistente produce diferencias que el contador detecta de inmediato y erosiona la confianza en el producto.

Ecuador está dollarizado: no hay conversión de moneda en el MVP.

## Decisión

1. Todos los importes monetarios se persisten como **`bigint` en centavos** con sufijo `_cents`.
2. En PHP se opera con **`brick/money`** (minor units enteras) y contexto USD; nunca con `float`.
3. Cantidades y precios unitarios usan `numeric(18,6)` y se tratan como cadenas decimales; los totales se calculan en centavos.
4. Regla de redondeo única: **HALF_UP** al convertir a centavos, aplicada una sola vez por operación documentada.
5. Las tasas y porcentajes usan `numeric(9,6)`.
6. Los formularios se calculan sumando centavos exactos; el redondeo solo se aplica al expresar resultados derivados (p. ej. proporcionalidad).
7. Los tests comparan enteros y cadenas exactas; se prohíbe `assertEqualsWithDelta` para dinero salvo tolerancias explícitas de reconciliación del emisor (±0,01 por factura).

## Consecuencias

**Positivas**
- Sumas y comparaciones exactas; los totales cuadran siempre.
- Compatible con exportaciones y PDF sin sorpresas.
- Fácil de auditar (un entero no tiene ambigüedad).

**Negativas**
- Toda la capa de presentación debe formatear (`Intl`, `brick/money`), no imprimir el entero.
- Los reportes SQL deben dividir por 100 con `numeric`, no con `float`.
- Un `SUM()` sobre centavos puede desbordar `int` de 32 bits en agregados grandes: en PHP se usa `int` de 64 bits; en Postgres `bigint`.

## Alternativas consideradas

| Alternativa | Por qué no |
|---|---|
| `decimal(18,2)` en base y `float` en PHP | El problema no es la base sino la aritmética en PHP |
| `decimal` + `bcmath` manual | Más propenso a errores y sin semántica de moneda; `brick/money` ya lo resuelve |
| Guardar strings | Dificulta agregaciones y comparaciones |

## Reglas de implementación

- Columna: `total_cents bigint not null`.
- Nunca `$invoice->total / 100` sin formateador; usar `Money::ofMinor($cents, 'USD')`.
- Los campos de entrada del usuario en USD se convierten a centavos en el borde (FormRequest/DTO).
- Los tests de calculadoras incluyen casos de `0.005`, `0.015` y valores con seis decimales.

## Enmienda 2026-11-15: `Money` propio sobre `brick/money`

El punto 2 se materializa en un value object del dominio,
`App\Domain\Shared\Values\Money`, que encapsula `brick/money` (instalado como
dependencia directa `^0.15`). El dominio no usa `Brick\Money\Money` ni
`Brick\Math\BigDecimal` directamente para importes: entra por `Money::cents()`
(línea base de datos y cálculos) o `Money::ofDecimal()` (borde de entrada) y
sale por `toCents()` / `decimal()`.

| Punto 2 (original) | Implementación |
|---|---|
| `Money::ofMinor($cents, 'USD')` | `Money::cents($cents)` |
| `$money->getMinorAmount()` | `$money->toCents()` |
| `$money->getAmount()` | `$money->decimal()` |

La regla única de redondeo HALF_UP vive en `Money::ofDecimal()` (conversión a
centavos) y `Money::multipliedBy()` (derivados como la proporcionalidad), de
modo que ninguna calculadora repite el redondeo. La moneda es fija (`USD`)
porque Ecuador está dollarizado y el MVP no convierte divisas.
