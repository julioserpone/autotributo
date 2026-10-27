# 10 — Integración SRI

> Alcance: F2-01 a F2-07 y F2-06 (factura, NC y ND). El paquete consulta el
> XML por clave de acceso, el robot descarga los reportes, la app lee su bucket
> S3, orquesta los runs con idempotencia, recibe el callback firmado del robot,
> reconcilia cada run contra el manifiesto e interpreta el TXT de claves. La
> persistencia de comprobantes (F2-08) sigue pendiente, y el parseo de
> liquidaciones de compra y retenciones es la segunda parte de F2-06.

## 0. Decisión: dónde vive el parseo

`julioserpone/sri-comprobantes-php` es el paquete del dominio de comprobantes
(generación, validación XSD y consulta de autorización). El **parseo de
comprobantes recibidos** es lógica de ese mismo dominio y debería vivir allí,
no en la aplicación. Hoy el paquete todavía no lo implementa: `Autorizacion`
expone el XML autorizado como texto y la app es quien lo interpreta.

Por eso el parseo entra como **puerto + adaptador**:

| Pieza | Rol |
|---|---|
| `SriInvoiceParser` (contrato) | Lo que consume el dominio; no menciona el paquete ni DOM |
| `App\Integrations\Sri\PackageSriInvoiceParser` | Adaptador registrado en `SriServiceProvider` |
| `JulioSerpone\SriComprobantes\Comprobante\ComprobanteReader` | Implementación v0.2.0 del paquete (01, 03, 04, 05, 06, 07) |

**Migrado:** desde `v0.2.0` el paquete incluye `ComprobanteReader` y la app ya
no tiene parser propio; `PackageSriInvoiceParser` solo traduce el DTO del
paquete al del dominio. Lo mismo aplica
a `SriAccessKey`, que ya delega el módulo 11 en
`JulioSerpone\SriComprobantes\AccessKey\AccessKeyGenerator`.

El manifiesto y el JSON de claves **sí** se validan en la app (F2-05): son el
contrato operativo con `sri-txt-robot`, no del formato fiscal del SRI.

## 1. Paquete `sri-comprobantes-php`

Se instala desde GitHub por VCS, porque no está en Packagist:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/julioserpone/sri-comprobantes-php.git"
    }
  ],
  "require": {
    "julioserpone/sri-comprobantes-php": "^0.1"
  }
}
```

La versión resuelta es `v0.2.0`, que incluye:

- `Client\AutorizacionClient` y `Transport\SoapTransport`.
- `Config\SriEnvironment` y `Config\SriService`.
- DTO de autorización, mensajes, estados y errores.
- `RetryPolicy` para fallos de transporte.

No se usa la rama local `feature/consulta-masiva-txt`: no está publicada y no
es reproducible en Laravel Cloud. La consulta masiva se orquesta en la app.

## 2. Puertos de dominio

```text
app/Domain/Sri/
├── Actions/
│   ├── StartSriSync.php              # crea y despacha runs idempotentes
│   ├── ReconcileSriRun.php           # reconcilia un run contra S3
│   └── ReconcileSriRuns.php          # selecciona y reconcilia runs activos
├── Contracts/
│   ├── SriAuthorizationGateway.php   # XML por clave
│   ├── SriRobotGateway.php           # descarga de TXT
│   └── SriRobotObjectStore.php       # lectura del bucket del robot
├── Data/
│   ├── AuthorizationQuery.php
│   ├── AuthorizationResult.php
│   ├── SriMessage.php
│   ├── SriRobotCredentials.php       # sobre que se sella
│   ├── SriRobotRequest.php
│   ├── SriRobotDispatch.php
│   ├── SriRobotObjectPath.php        # layout {ambiente}/{ruc}/{periodo}
│   ├── SriSyncBatch.php              # runs de una solicitud
│   └── SriSyncWindow.php             # agrupación y corte de 93 días
├── Enums/
│   ├── AuthorizationStatus.php
│   ├── SriDocumentType.php
│   ├── SriSyncRunFileStatus.php
│   └── SriSyncRunStatus.php
└── Models/
    ├── SriSyncRun.php
    └── SriSyncRunFile.php
```

Adaptadores de infraestructura:

| Clase | Uso |
|---|---|
| `PackageSriAuthorizationGateway` | Consulta SOAP real con el paquete |
| `LambdaSriRobotGateway` | Invoca `sri-txt-robot` en AWS Lambda (`Event`) |
| `FakeSriAuthorizationGateway` | Desarrollo y tests sin red |
| `FakeSriRobotGateway` | Desarrollo y tests sin AWS |
| `FilesystemSriRobotObjectStore` | Lectura del disco `sri` (S3 en Cloud) |
| `ReconcileSriRunsCommand` | Comando `sri:reconcile-runs` programado cada 10 min |

El dominio solo depende de los contratos. Ningún controlador o job debe
importar clases de SOAP o del SDK de AWS.

## 3. Contrato del robot

Evento real del handler de `sri-txt-robot`:

```json
{
  "ruc": "1758871873001",
  "periodos": ["2026-08", "2026-09"],
  "tipos": ["01", "03", "04", "05", "07"],
  "forzar": false,
  "sealed_credentials": "base64(sodium_crypto_box_seal({...}))"
}
```

Reglas:

- `periodos` acepta `YYYY-MM` o `YYYY-MM-DD`.
- `tipos` usa los códigos de la ficha técnica; una lista vacía deja que el
  robot use los tipos configurados en su YAML.
- El robot puede operar en **modo estático** (contraseña en la Lambda) o
  **dinámico** (el evento trae `sealed_credentials`). La aplicación usa el
  modo dinámico: sella `{ruc, password, claveAdicional, iat}` con
  `sodium_crypto_box_seal` y la clave pública de `SRI_ROBOT_PUBLIC_KEY`.
- La clave privada correspondiente vive en la variable
  `SRI_CREDENTIALS_PRIVATE_KEY` de la Lambda, no en AWS Secrets Manager. La
  contraseña nunca viaja en claro y no se persiste en el payload.
- El `ruc` exterior y el interior del sobre deben coincidir. La Lambda rechaza
  sobres caducados (`SRI_CREDENTIALS_MAX_AGE_SECONDS`).
- El bucket S3 vive en la configuración de la Lambda, no en el payload de
  Laravel.
- La invocación es asíncrona (`InvocationType=Event`); la respuesta es un
  `RequestId`, no el resultado de la descarga.
- El robot envía un callback HTTP firmado al terminar; la app correlaciona el
  evento por `event_id` y `sri:reconcile-runs` valida después los archivos en
  S3. El callback acelera la transición, pero no sustituye la reconciliación.

Salida esperada del robot en su bucket:

```text
{ambiente}/{ruc}/{periodo}/facturas-recibidas-{periodo}.txt
{ambiente}/{ruc}/{periodo}/facturas-recibidas-{periodo}-claves.json
{ambiente}/{ruc}/{periodo}/manifest.json
```

`SriRobotObjectPath` construye estas claves en el dominio; el prefijo opcional
del bucket lo aplica el disco `sri` de Laravel (`SRI_ROBOT_OUTPUT_PREFIX`). El
robot **no** publica checksums en el manifiesto actual: la app calcula SHA-256
al leer y lo persiste en `sri_sync_run_files` durante F2-05.

### Lectura desde Laravel

| Pieza | Responsabilidad |
|---|---|
| `SriRobotObjectPath` | Genera claves estables para TXT, JSON de claves y manifiesto |
| `SriRobotObjectStore` | Contrato de existencia, lectura y SHA-256 |
| `FilesystemSriRobotObjectStore` | Adaptador del disco `sri` (S3 en Cloud) |

La app solo lee el bucket del robot. El rol IAM del robot es el único que
escribe; el usuario de la app no necesita `s3:PutObject` sobre `-sri`. Además
de `s3:GetObject`/`s3:ListBucket` en el prefijo de salida, la identidad de la
app necesita `lambda:InvokeFunction` sobre
`arn:aws:lambda:us-east-2:076767082111:function:sri-txt-robot`. El usuario de
despliegue (`sri-txt-robot-deployer`) no debe reutilizarse como credencial de la
aplicación: tiene `AWSLambda_FullAccess` y permisos de más.

### Orquestación de runs (F2-03)

`StartSriSync` recibe una empresa, un `SriRobotRequest` (con credenciales) y
una clave de idempotencia. Crea uno o varios `SriSyncRun` y despacha el robot:

| Pieza | Responsabilidad |
|---|---|
| `SriSyncWindow` | Agrupa períodos completos y corta rangos mayores a 93 días |
| `SriSyncBatch` | Devuelve los runs creados por una solicitud |
| `SriSyncRunStatus` | Máquina de estados (`queued` → `invoking` → `downloading` → `parsing` → `completed`/`partial`/`failed`/`cancelled`) |
| `SriSyncRun` | Persiste empresa, rango, tipos, `idempotency_key`, `lambda_request_id`, totales y error seguro |
| `StartSriSync` | Idempotencia, división, despacho y marcado de fallos |

Reglas:

- Una solicitud con períodos que cubren más de 93 días se divide en varios
  runs. Los meses no se cortan: se agrupan períodos completos.
- La misma `idempotency_key` devuelve los runs existentes y **no** vuelve a
  invocar la Lambda. Para reintentar un run fallido se usa una clave nueva.
- El estado del run se mueve solo con transiciones permitidas; un estado
  terminal no se reabre.
- Si el gateway falla, el run queda `failed` con `error_code` y un mensaje
  seguro; la excepción se propaga al llamador.
- La cancelación se hace con `SriSyncRun::cancel()` y solo aplica a runs
  activos. La cancelación de la invocación asíncrona real queda pendiente de
  F2-09.
- La tabla `companies` se incorporó con lo mínimo para la FK del run; el CRUD
  completo y la validación de RUC pertenecen a F1-05.
- `sri_sync_run_files` se llena en F2-05; el parser de F2-06 consume esos
  archivos y el de F2-07 interpreta el manifiesto/TXT.

### Callback firmado (F2-04)

Cada invocación incluye un `event_id` (ULID generado por la app) y la
`callback_url` configurada. El run guarda ese identificador en
`callback_event_id`, así el callback se correlaciona sin ambigüedad.

El robot envía al terminar (éxito, parcial o fallo):

```text
POST /api/v1/webhooks/sri-robot
Content-Type: application/json
X-Sri-Event-Id: 01J9Z8Q7K3...
X-Sri-Timestamp: 1792791111
X-Sri-Signature: sha256=<hex>
X-Sri-Delivery-Attempt: 1
```

- **Firma:** `HMAC-SHA256(secreto, "{timestamp}.{cuerpo exacto}")`.
- **Ventana:** ±300 s (`SRI_ROBOT_CALLBACK_TOLERANCE_SECONDS`).
- **Tamaño:** 256 KB (`SRI_ROBOT_CALLBACK_MAX_BYTES`).
- **Dedupe:** `event_id` único en `sri_robot_callbacks`; un duplicado
  responde `202` sin reprocesar.
- **Respuesta:** `202 accepted` en éxito y duplicado; `401` firma o timestamp
  inválidos; `413` cuerpo grande; `422` contrato inválido; `503` secreto no
  configurado (el robot reintenta).

Efecto en el run:

- `completed`/`partial`: `invoking` → `downloading`, listo para que
  `sri:reconcile-runs` valide el manifiesto.
- `failed`: el run pasa a `failed` con `error_code=robot_failed`.
- Un run ya final no se reabre; el evento se guarda igual.

El receptor vive en `App\Domain\Sri\Http\Middleware\VerifySriRobotCallback` y
`SriRobotCallbackController`; la ruta no usa sesión ni contexto de tenant.

### Reconciliación por S3 (F2-05)

Además del callback, la app reconcilia cada 10 minutos los runs activos
contra el bucket del robot. El callback acelera la transición, pero la
reconciliación sigue siendo la que valida los archivos:

```text
sri:reconcile-runs --limit=50   # programado con withoutOverlapping()
```

`ReconcileSriRuns` selecciona runs en `invoking`, `downloading` o `parsing`.
`ReconcileSriRun` procesa cada período persistido en `sri_sync_runs.periods`
(con respaldo mensual para runs antiguos) y usa este manifiesto real:

```json
{
  "ambiente": "produccion",
  "ruc": "1758871873001",
  "periodo": "2026-09",
  "generadoEn": "2026-10-21T16:45:48+00:00",
  "descargas": [
    {
      "ruc": "1758871873001",
      "periodo": "2026-09",
      "tipo": "01",
      "tipoEtiqueta": "Factura",
      "claveTxt": "produccion/1758871873001/2026-09/facturas-recibidas-2026-09.txt",
      "bytes": 8164,
      "totalClaves": 44,
      "tareaCaptcha": "navegador"
    }
  ],
  "totalClaves": 44
}
```

El JSON de claves asociado tiene `ambiente`, `ruc`, `periodo`, `tipo`,
`tipoEtiqueta`, `archivo`, `total` y `clavesAcceso[]`. La reconciliación
valida que `ambiente`, `ruc` y `periodo` coincidan con el run, que el tipo sea
conocido, que `bytes` coincida con el TXT, que `total` coincida con
`count(clavesAcceso)` y que `archivo` sea el nombre del TXT.

Reglas de estado:

| Situación | Estado del run |
|---|---|
| Aún no existe ningún `manifest.json` | Se queda en `invoking` |
| Hay al menos un manifiesto | Pasa a `downloading` |
| Todos los períodos y archivos están disponibles y válidos | Pasa a `parsing` |
| Falta un TXT, un JSON o hay un archivo inválido | Se queda en `downloading` con el detalle en `sri_sync_run_files` |

Cada objeto se registra con `updateOrCreate` por `(run_id, s3_key)` para que
repetir la reconciliación sea idempotente. Estados de archivo:
`pending`, `available`, `missing`, `invalid` y `processed` (este último lo
usará el parser). Un fallo de un run no detiene la reconciliación de los
siguientes; el error se reporta y el scheduler reintenta en la próxima pasada.

## 4. Parseo de los archivos del robot (F2-07) y del XML (F2-06)

### TXT de claves

`ParseSriRun` toma los runs en `parsing` (o los reconcilia si están en
`downloading`) y, por cada TXT disponible:

```text
sri:parse-run {run|public_id}   # manual; el scheduler lo hace en F2-09
```

| Pieza | Responsabilidad |
|---|---|
| `SriAccessKey` | Decodifica fecha, tipo, RUC, ambiente, serie y secuencial; valida estructura y verificador |
| `AccessKeyListParser` | Lee una clave por línea, tolera BOM/CRLF/espacios, devuelve líneas inválidas con motivo |
| `PeriodBatchBuilder` | Cruza el TXT con el JSON de claves y descarta claves de otro tipo, período o RUC |
| `SriPeriodBatch` | Lote listo para consultar: claves válidas, inválidas, faltantes y no declaradas |
| `ParseSriRun` | Cierra el run en `completed`, `partial` o `failed` y marca los archivos `processed` |

Reglas:

- Una línea inválida no invalida el archivo: se registra el motivo y el run
  queda `partial` si el resto es procesable.
- Un tipo solicitado en el run que no produjo ningún lote cuenta como paquete
  incompleto (`partial`), no como run completo.
- Sin ninguna clave válida el run pasa a `failed` con
  `error_code=no_access_keys`.
- Repetir el comando no duplica nada: solo se leen archivos `available` y un
  run final no se mueve.

### XML del comprobante

La lectura la hace `ComprobanteReader` del paquete (v0.2.0). Soportado:
factura (`01`), liquidación de compra (`03`), nota de crédito (`04`), nota de
débito (`05`), guía de remisión (`06`) y comprobante de retención (`07`).
El adaptador `PackageSriInvoiceParser` traduce al modelo de la app y aporta:

- XXE desactivado (`LIBXML_NONET`, sin `LIBXML_NOENT`, sin DTD) y límite de
  5 MiB.
- Montos a centavos con aritmética de cadenas (sin `float`), redondeo al
  centavo.
- Los totales inconsistentes **no se corrigen**: `SriInvoiceData::totalsMismatch()`
  queda en `true` para que F2-08 los registre como hallazgo.
- `normalizedDescription` (sin acentos, mayúsculas, espacios colapsados) se
  calcula aquí porque es la clave de las reglas y la caché de IA.
- La tarifa (`<tarifa>`) solo viene en el detalle; la cabecera trae
  `codigoPorcentaje`, que es lo que se declara en el 104.
- El código de impuesto del SRI se mapea a `IR` (1), `IVA` (2), `ICE` (3) o
  `IRBPNR` (5); en retenciones, `SriRetentionData` lleva tipo, código, base,
  porcentaje, valor retenido y documento sustento.
- Un XML malformado, un raíz no soportado o un campo obligatorio ausente lanzan
  `SriXmlException` con motivo, nunca el contenido del documento.

## 5. Variables de entorno

```dotenv
SRI_AUTHORIZATION_DRIVER=package   # package | fake
SRI_ENVIRONMENT=1                  # 1 pruebas, 2 producción
SRI_CONNECTION_TIMEOUT=10
SRI_TIMEOUT=30
SRI_RETRY_ATTEMPTS=3
SRI_RETRY_BASE_DELAY_MS=250
SRI_RETRY_MAX_DELAY_MS=5000

SRI_ROBOT_DRIVER=fake              # fake | lambda
SRI_ROBOT_FUNCTION=sri-txt-robot
SRI_ROBOT_REGION=us-east-2
SRI_ROBOT_ENVIRONMENT=dev          # dev | test | produccion (debe coincidir con la Lambda)
SRI_ROBOT_OUTPUT_BUCKET=autotributo-local-sri
SRI_ROBOT_OUTPUT_PREFIX=           # prefijo opcional; vacío por defecto
SRI_ROBOT_CALLBACK_URL="${APP_URL}/api/v1/webhooks/sri-robot"
SRI_ROBOT_CALLBACK_SECRET=         # mínimo 16 caracteres
SRI_ROBOT_CALLBACK_TOLERANCE_SECONDS=300
SRI_ROBOT_CALLBACK_MAX_BYTES=262144
SRI_ROBOT_PUBLIC_KEY=              # base64 de la clave pública del robot
```

`SRI_ROBOT_PUBLIC_KEY` se obtiene con
`sri-txt-robot generar-claves --publica <archivo>` o del archivo
`~/.sri-txt-robot/publica.b64`. No confunda esta clave con
`SRI_CREDENTIALS_KEY`: la primera sella el sobre para el robot; la segunda
cifra las credenciales en la base de datos de la app.

En local, `SRI_AUTHORIZATION_DRIVER=fake` evita golpear el SRI y
`SRI_ROBOT_DRIVER=fake` evita AWS. En staging/producción se usa `package` y
`lambda`.

## 6. Pruebas

| Archivo | Qué prueba |
|---|---|
| `tests/Unit/Sri/PackageSriAuthorizationGatewayTest.php` | Autorizado, rechazado, PPR, sin respuesta y fallo de transporte |
| `tests/Unit/Sri/SriRobotRequestTest.php` | Payload exacto, deduplicación, validaciones y sobre sellado |
| `tests/Unit/Sri/SriRobotCredentialsTest.php` | Contenido del sobre, `iat` y redacción de la contraseña |
| `tests/Unit/Sri/LambdaSriRobotGatewayTest.php` | Payload de invocación, modo `Event`, `RequestId`, sellado y error AWS |
| `tests/Unit/Sri/SriRobotObjectPathTest.php` | Layout real, slugs, períodos diarios y validaciones |
| `tests/Unit/Sri/SriSyncWindowTest.php` | Agrupación, corte exacto de 93 días y rangos sin huecos |
| `tests/Unit/Sri/SriSyncRunStatusTest.php` | Transiciones permitidas, estados terminales y saltos inválidos |
| `tests/Feature/Sri/StartSriSyncTest.php` | Creación, idempotencia, división, fallo y cancelación |
| `tests/Feature/Sri/ReconcileSriRunTest.php` | Manifiesto/TXT/claves, faltantes, JSON inválido, bytes, identidad, idempotencia, multiperíodo, comando y tenant por defecto |
| `tests/Feature/Sri/SriRobotObjectStoreTest.php` | Lectura, existencia, SHA-256 y objeto ausente en el disco `sri` |
| `tests/Feature/Sri/SriServiceProviderTest.php` | Selección de adaptadores por configuración |
| `tests/Unit/Sri/SriAccessKeyTest.php` | Estructura, tipos, verificador (módulo 11) y claves mal formadas |
| `tests/Unit/Sri/AccessKeyListParserTest.php` | TXT con BOM/CRLF, líneas inválidas, duplicados y cruce con el JSON de claves |
| `tests/Feature/Sri/ParseSriRunTest.php` | Run `completed`/`partial`/`failed`, marca de archivos, idempotencia y comando |
| `tests/Unit/Sri/ComprobanteReaderIntegrationTest.php` | Los seis tipos por el adaptador: totales, ítems, impuestos, retenciones, XXE, tamaño y XML malformado |

No hay red en la suite: el transporte SOAP y el cliente Lambda se sustituyen
por dobles.

## 7. Prueba end-to-end en local

Para validar el callback contra la Lambda real sin desplegar la app:

1. Expón el entorno local con un túnel HTTPS. Expose Free asigna un subdominio
   aleatorio en cada arranque, así que hay que actualizar la Lambda cuando
   cambie:

   ```bash
   expose share 127.0.0.1:8000
   aws lambda update-function-configuration --function-name sri-txt-robot \
     --region us-east-2 --environment 'Variables={...SRI_CALLBACK_URL=https://<subdominio>.sharedwithexpose.com/api/v1/webhooks/sri-robot,...}'
   ```

   `SRI_CALLBACK_URL` es la URL por defecto. Si el evento trae su propia
   `callback_url`, el robot solo la usa cuando su host está en
   `SRI_CALLBACK_ALLOWED_HOSTS`; por eso la enviamos desde la app y la
   autorizamos en la misma actualización.

2. Usa una identidad IAM mínima para la app (no el usuario de despliegue):
   `lambda:InvokeFunction` sobre la función del robot y `s3:GetObject`/
   `s3:ListBucket` sobre el prefijo de salida. Con esas claves en `.env`
   (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`,
   `SRI_ROBOT_OUTPUT_BUCKET`) el contenedor deja de buscar credenciales en el
   metadata service.

3. Con `SRI_ROBOT_DRIVER=lambda`, `SRI_ROBOT_ENVIRONMENT=produccion` y el mismo
   `SRI_ROBOT_CALLBACK_SECRET` en ambos lados, lanza un run con
   `StartSriSync` y sigue el resultado en `sri_robot_callbacks`.

   La clave pública que usa la app debe ser la del par de la Lambda. No se
   deriva de la privada como semilla de Ed25519: `sri-txt-robot` genera
   `nacl.public.PrivateKey`, y su pública se obtiene con
   `base64(bytes(PrivateKey(sk).public_key))`. Si se sella con otra clave, el
   robot responde `failed` con
   `CredencialesError: No se pudo descifrar 'sealed_credentials'`.

Resultado esperado de una corrida válida: callback `202` (guardado en
`sri_robot_callbacks`), correlación por `event_id` y el run pasando de
`invoking` a `downloading` para que `sri:reconcile-runs` valide el manifiesto.

## 8. Siguientes pasos

1. **F2-06 (b):** liquidaciones de compra (`03`) y comprobantes de retención
   (`07`), y la consulta al SRI por clave de acceso
   (`SriAuthorizationGateway`) dentro del flujo.
2. **F2-08:** persistencia idempotente en `invoices`/`invoice_items` con
   `upsert` por `access_key`.
3. **F1-05/F1-06:** CRUD de empresas y almacenamiento cifrado de credenciales.
4. **F2-09:** progreso en vivo y cancelación de la invocación asíncrona.
5. **Rendimiento:** medir 5.000 XML con `ComprobanteReader` (benchmark de
   F2-08) y, si hace falta, paralelizar por lotes.

## 9. Seguridad

- No registrar XML completos, credenciales ni claves de acceso completas.
- Las credenciales del portal se sellan con `sodium_crypto_box_seal` antes de
  salir de la app; el payload y los logs solo contienen el sobre.
- No registrar `SriRobotCredentials` ni llamar a `toSealingPayload()` fuera
  del sellador. `__debugInfo()` redacta la contraseña.
- Mantener `SRI_ROBOT_PUBLIC_KEY` y `SRI_CREDENTIALS_PRIVATE_KEY` separadas:
  la pública puede vivir en la app; la privada solo en la Lambda.
- No desactivar TLS ni `verify_peer` en `SoapTransport`.
- Mantener `SRI_CREDENTIALS_KEY` fuera del repositorio y con respaldo en el
  gestor de secretos.
