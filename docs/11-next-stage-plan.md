# 11 — Siguiente etapa: plan de trabajo

> Documento de planificación. La fuente de verdad del alcance sigue siendo
> `docs/02-implementation-plan.md` y el avance real vive en `docs/09-progress.md`.

**Fecha:** 2026-10-27 · **Rama:** `feat/F2-07-parser-manifiesto-txt`

## 1. Diagnóstico del estado actual

Al cerrar F2-05 el pipeline funciona hasta aquí:

```text
StartSriSync → Lambda (Event) → callback firmado → run en downloading
                              → sri:reconcile-runs → manifiesto/TXT/claves en sri_sync_run_files
                                                    → run en parsing
```

Nadie consume el estado `parsing`. Los runs se quedan ahí, y no existe todavía
la tabla `invoices`. En la práctica:

| Tarea | ¿Se puede hacer ya? | Motivo |
|---|---|---|
| F2-06 Parser XML | Sí, pero a ciegas | `SriAuthorizationGateway` consulta el XML por clave de acceso; el contrato ya existe y el TXT del robot lo alimenta. Falta una pieza previa: interpretar las claves del JSON. |
| F2-07 Parser de manifiesto/TXT | **Sí, directamente** | Es el insumo inmediato de la reconciliación; no depende de nada pendiente. |
| F2-08 Persistencia idempotente | No todavía | Requiere el parser de F2-06/F2-07. |
| F2-09 Progreso en vivo | Bloqueada | F1-08 (Reverb) está pendiente. |
| F2-10/F2-11/F2-12 Resiliencia, cuotas, observabilidad | Dependen de F2-08 para medir correctamente. | |
| F1-03…F1-11 Identidad, autorización, empresas, credenciales | Independiente de F2 | Alternativa válida para paralelizar con otra persona. |
| F3 Motor fiscal + IA | No | Necesita comprobantes persistidos. |

## 2. Decisión

La siguiente etapa es **cerrar el tramo de parsing de F2**, empezando por
**F2-07**:

1. **F2-07 · Parser de manifiesto/TXT** — es el contrato que ya está en disco y
   que la reconciliación acaba de validar. Entrega valor inmediato: saber, para
   cada run en `parsing`, qué claves hay que consultar en el SRI y qué falló en
   los archivos del robot, sin llamar a nadie todavía.

   > **Aclaración de alcance:** el manifiesto y el JSON de claves se validan en
   > `ReconcileSriRun` (F2-05) porque son la fuente de verdad de la descarga. El
   > TXT añade lo que el JSON de claves no garantiza: la lista ordenada de claves,
   > su formato y la coherencia con `periodo`/`tipo`. F2-07 interpreta el TXT y
   > produce el lote de trabajo que consumirá el parser XML.

2. **F2-06 · Parser XML (factura, NC, ND)** — se construye como unidad de dominio
   pura (XML → DTO), sin persistir, para poder probarla con fixtures sin tocar la
   base. La persistencia llega en F2-08.

## 3. Plan de trabajo

| # | Entregable | Criterio de aceptación | Pruebas |
|---|---|---|---|
| 1 | `SriAccessKey` (unit) | Valida 49 dígitos y dígito verificador módulo 11; expone tipo, RUC emisor, ambiente, periodo de emisión | Tabla de casos válidos/inválidos, residuo 0/1 |
| 2 | `AccessKeyListParser` (F2-07) | Lee el TXT del robot (`produccion/{ruc}/{periodo}/{slug}-recibidas-{periodo}.txt`), una clave por línea, tolera BOM/CRLF/blancos, normaliza y **rechaza** líneas inválidas con motivo | Unit con fixture real; errores por línea |
| 3 | `SriTextReportParseResult` | Devuelve claves válidas, inválidas y duplicadas sin lanzar excepción | Unit |
| 4 | `SriPeriodBatch` (dominio) | Cruza las claves del TXT con el JSON de claves de la reconciliación; detecta faltantes/sobrantes por tipo; compara `SriDocumentType` | Unit |
| 5 | `ParseSriRun` (Action) | Solo para runs en `parsing`; marca `sri_sync_run_files.status = processed` y `processed_at`; si no hay claves válidas, deja el run en `partial` con motivo | Feature con `Storage::fake('sri')` |
| 6 | `sri:parse-run {run}` | Comando idempotente para operar un run puntual sin depender del scheduler | Feature |
| 7 | Lector de comprobantes (paquete v0.2.0) | 01, 03, 04, 05, 06 y 07 → DTO con cabecera, ítems, impuestos, retenciones y totales; XXE deshabilitado; XML malformado lanza `SriXmlException` sin perder el motivo | Unit en el paquete + integración en la app |
| 8 | Validación de totales | `total = subtotal − descuento + impuestos + ICE + IRBPNR + propina`; si no cuadra, el DTO expone `totalsMismatch` en lugar de corregirlo | Tabla de casos |
| 9 | Factories y fixtures | `tests/Fixtures/xml/{invoice,credit_note,debit_note}` con `README.md` por carpeta | — |
| 10 | Documentación | `docs/10-sri-integration.md` actualizado, `docs/09-progress.md` con evidencia | — |

**Fuera de esta rama (siguiente PR):** liquidación de compra y comprobantes de
retención (F2-06 b), persistencia `invoices`/`invoice_items` (F2-08), consulta al
SRI por clave (`SriAuthorizationGateway`) dentro del flujo.

## 3.1 Decisión sobre `sri-comprobantes-php`

El paquete ya está en Packagist y se resuelve sin el bloque `repositories`; se
eliminó de `composer.json`. Revisado el `src/` de v0.1.2, cubre generación
(`LoteBuilder`, `RideBuilder`), validación XSD y la consulta SOAP de
autorización (`Autorizacion` con el XML como texto). **No incluye el parseo de
comprobantes recibidos**, que es justo lo que necesita F2-06.

Sí es el lugar correcto para que viva: es lógica del formato fiscal del SRI, no
de la aplicación, y así cualquier consumidor del paquete reutiliza la lectura.

**Hecho:** se implementó `Comprobante\ComprobanteReader` en el paquete (PR #5,
`v0.2.0`) con los seis tipos, y la app migró a esa versión. `SriXmlParser` y
`SriXmlValues` se eliminaron. Estado final:

| Capa | Módulo | Dependencia del paquete |
|---|---|---|
| Dominio | `SriAccessKey` | **Sí**: delega el módulo 11 en `AccessKeyGenerator` |
| Dominio | `SriInvoiceParser` (contrato) | No |
| Integración | `PackageSriInvoiceParser` | **Sí**: punto único de sustitución |
| Implementación | `JulioSerpone\SriComprobantes\Comprobante\ComprobanteReader` (paquete) | **Sí** |

Los tests del lector viven ahora en el paquete
(`tests/Unit/ComprobanteReaderTest.php`); en la app queda el test del adaptador
(`tests/Unit/Sri/ComprobanteReaderIntegrationTest.php`), que verifica la
traducción al modelo y sirve de contrato contra la versión instalada.

## 4. Riesgos

| Riesgo | Mitigación |
|---|---|
| El robot puede cambiar el formato del TXT | El parser tolera BOM, CRLF, líneas vacías y espacios; cualquier cambio de estructura falla con motivo y no con un run `completed` falso |
| XML con entidades externas (XXE) | `libxml_disable_entity_loader`/`LIBXML_NONET` y sin `LIBXML_NOENT`; test explícito con `<!ENTITY` |
| Totales inconsistentes del emisor | Se registran, no se corrigen: la corrección silenciosa ocultaría errores fiscales (invariante #1 de `docs/03-data-model.md`) |
| Duplicar validación con F2-05 | F2-05 valida identidad y checksum; F2-07 valida **forma de la clave** y coherencia de contenido. Documentado en `docs/10` |

## 5. Consejo de secuencia

- **Si hay una segunda persona:** F1-03/F1-04 pueden avanzar en paralelo sin
  tocar este código.
- **Antes de F2-09** hay que cerrar F1-08 (Reverb).
- **F2-08** conviene escribirlo en cuanto F2-06a esté aprobado: es lo que desbloquea F3.
