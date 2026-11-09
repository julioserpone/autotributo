# 10 — Integración SRI

> Alcance: F2-01 a F2-08 y F2-11. El paquete consulta el XML por clave de
> acceso, el robot descarga los reportes, la app lee su bucket S3, orquesta los
> runs con idempotencia y cuotas, recibe el callback firmado del robot,
> reconcilia cada run contra el manifiesto, interpreta el TXT de claves y
> persiste los comprobantes. El parseo de liquidaciones de compra y
> retenciones es la segunda parte de F2-06.

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

## 2.1 Credenciales del portal (F1-06)

La contraseña del contribuyente se guarda cifrada y solo se descifra en el
momento de invocar al robot.

| Pieza | Rol |
|---|---|
| `sri_credentials` | Una fila por empresa (`unique(tenant_id, company_id)`): `username`, `password_ciphertext`, `key_version`, `status`, fechas de uso y `failure_count` |
| `sri_credential_accesses` | Rastro de cada descifrado, rotación y borrado (`purpose`: `sync`, `rotation`, `support`) |
| `SriCredentialCipher` | `sodium_crypto_secretbox` con `SRI_CREDENTIALS_KEY`; blob `v1:{key_version}:{nonce}:{ciphertext}` |
| `ReadSriCredential` | Único punto de descifrado; registra el acceso **antes** de devolver el secreto |
| `BuildSriRobotRequest` | Arma el `SriRobotRequest` con las credenciales persistidas; `StartSriSync` no cambia |

Reglas:

- `SRI_CREDENTIALS_KEY` es una clave dedicada de 32 bytes en base64, distinta de
  `APP_KEY`. Si falta o es inválida, la app falla al resolver el cifrador (en
  `testing` se genera una efímera).
- `key_version` permite rotar la clave: una fila cifrada con otra versión falla
  con un `409` accionable en lugar de devolver basura.
- El secreto nunca aparece en `json_encode`, `dump`, logs ni en la respuesta
  HTTP; `SriCredentialResource` solo expone `has_password: true`.
- Tras tres fallos de autenticación la credencial pasa a `invalid` y el sync
  responde `409` hasta que se actualice.
- Archivar la empresa borra la credencial (retención de `docs/03` §13).

Endpoints:

| Método | Ruta | Permiso |
|---|---|---|
| `GET` | `/api/v1/companies/{company}/credential` | `credentials.view` |
| `PUT` | `/api/v1/companies/{company}/credential` | `credentials.manage` |
| `POST` | `/api/v1/companies/{company}/credential/rotate` | `credentials.manage` |
| `DELETE` | `/api/v1/companies/{company}/credential` | `credentials.manage` |
| `POST` | `/api/v1/sync-runs` (`Idempotency-Key` obligatoria) | `sync.start` |
| `GET` | `/api/v1/sync-runs/{run}` | `sync.view` |

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
{ambiente}/{ruc}/{periodo}/facturas-recibidas-{periodo}-xml/{clave}.xml
{ambiente}/{ruc}/{periodo}/manifest.json
```

Desde el upgrade de `sri-txt-robot` que guarda el XML de cada comprobante, la
carpeta `*-xml/` es la fuente principal de los XML: la aplicación ya no
depende de la respuesta del SRI ni de que el callback llegue.

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

### Recuperación desde S3 (sin callback)

La URL del callback (`https://mkcaihhbdd.sharedwithexpose.com/`) es un túnel y
puede estar caída cuando el robot termina. Para que el flujo no dependa de ella,
la aplicación verifica el bucket directamente:

| Pieza | Responsabilidad |
|---|---|
| `SriRobotObjectPath::xml()` | Clave `{periodo}/{tipo}-recibidas-{periodo}-xml/{clave}.xml` |
| `SriXmlRecovery` | Contrato: descubrir, localizar y leer XML del período |
| `S3SriXmlRecovery` | Verifica ambiente/RUC/período y registra los XML en `sri_sync_run_files` |
| `RecoverSriRunFromS3` | `invoking` → `downloading` → `parsing` cuando el período está verificado |

Reglas de verificación (obligatorias antes de leer cualquier XML):

1. El `manifest.json` del período coincide en `ambiente`, `ruc` y `periodo` con
   el run, y el tipo figura entre sus descargas.
2. Si el manifiesto aún no existe, basta con que exista el JSON de claves del
   tipo para ese período.
3. La clave buscada debe estar declarada en el JSON del lote
   (`clavesAcceso`) o, en su defecto, aparecer en el listado de `*-xml/`.

Sin verificación no se toca S3: un run de un período que el robot no descargó
nunca lee comprobantes de otro mes, y un manifiesto de otro RUC se ignora.

Si el robot terminó sin comprobantes (`descargas: []` en el manifiesto), el run
se cierra en `failed` con `error_code=no_documents` y `found=0`: no es un fallo
técnico, pero tampoco debe quedarse activo para siempre.

Comandos:

```text
sri:recover-run {run|public_id}     # verifica S3, reconcilia e importa
sri:recover-run {run} --no-import   # solo verifica y reconcilia
```

El callback sigue siendo atajo: al llegar, `SriRobotCallbackController` corre
la misma recuperación y, si el lote ya está en S3, deja el run en `parsing`.
La correlación es por `event_id`, así que cambiar el subdominio del túnel no
rompe los eventos que ya estaban en vuelo.

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

#### Ventana del endpoint de autorizaciones

Pruebas directas contra el portal muestran que el endpoint
`autorizacionComprobante` devuelve el XML de forma fiable solo para
comprobantes recientes (observado: hasta 50 días). Por eso `ImportSriRun`
decide el origen **por clave**, con la fecha de emisión decodificada de la
propia clave de acceso:

| Fecha del comprobante | Origen del XML |
|---|---|
| Dentro de `SRI_AUTHORIZATION_WINDOW_DAYS` (45 por defecto) | SRI (`SriAuthorizationGateway`) |
| Fuera de la ventana, fecha inválida o futura | S3 del robot (`SriXmlRecovery`) |
| Dentro de la ventana pero el SRI falla, no responde o no tiene el XML | S3 del robot |
| Dentro de la ventana y el SRI devuelve el XML | SRI |

Reglas:

- La ventana se mide en días calendario entre la emisión y hoy; se ajusta en
  caliente con `SRI_AUTHORIZATION_WINDOW_DAYS`.
- El respaldo a S3 ocurre ante `SriGatewayException` o XML inutilizable, sin
  abortar el run.
- Con `SRI_AUTHORIZATION_FALLBACK_TO_SRI=false` el SRI no se consulta nunca:
  todo XML se lee de S3.
- El orden es **SRI primero y S3 como respaldo dentro de la ventana**; fuera de
  ella, o con el respaldo desactivado, **S3 es la única fuente**.
- `ImportSriRun` guarda `totals.from_sri` y `totals.from_s3` para auditar el
  origen de cada corrida.

#### Sobre SOAP de la respuesta

El SRI envuelve la autorización en `soap:Envelope > soap:Body >
ns2:autorizacionComprobanteResponse > RespuestaAutorizacionComprobante`. El
paquete recibe el cuerpo ya desenvuelto; `PackageSriAuthorizationGateway`
normaliza el caso en que llegue el sobre completo (o su texto) leyendo el cuerpo
crudo de la última respuesta y extrayendo `RespuestaAutorizacionComprobante`:

- XXE desactivado (`LIBXML_NONET`, sin `LIBXML_NOENT` ni DTD).
- Los namespaces se descartan porque el DTO del paquete busca por nombre.
- El campo `comprobante` (XML escapado) se devuelve tal cual, listo para
  `ComprobanteReader`.
- Si el cuerpo no es XML o no trae la respuesta, el resultado es
  `SriGatewayException` y el flujo intenta S3.

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

### Persistencia (F2-08)

`ImportSriRun` recorre los lotes de `ParseSriRun` y, por cada clave, obtiene el
XML en este orden:

1. **SRI** (`SriAuthorizationGateway`), solo si la fecha de emisión está dentro
   de `SRI_AUTHORIZATION_WINDOW_DAYS` y `SRI_AUTHORIZATION_FALLBACK_TO_SRI`
   está activo.
2. **S3 del robot** (`SriXmlRecovery`): fuente única fuera de la ventana o con
   el respaldo desactivado, y respaldo cuando el SRI falla o no entrega el XML.
   Solo usa el archivo registrado si el período está verificado.

Luego lo interpreta y lo guarda con `PersistSriInvoice`. El run guarda
`totals.from_s3` y `totals.from_sri` para auditar el origen de cada corrida:

```text
sri:import-run {run|public_id}
```

| Situación | Resultado |
|---|---|
| Clave nueva | `created`, con ítems, impuestos y retenciones |
| Misma clave y mismo `xml_sha256` | `skipped`, no se escribe nada |
| Misma clave y XML distinto | `updated`, los hijos se reemplazan |
| XML ilegible o no autorizado | Cuenta en `failed`; el run sigue con el resto |

Reglas:

- `unique(tenant_id, company_id, access_key)` es la identidad del comprobante;
  la misma clave en dos empresas o dos tenants son filas distintas.
- `first_seen_at` guarda el primer run que vio el comprobante; `sync_run_id` y
  `updated_or_created_at`, el último que lo tocó.
- Un comprobante se escribe en su propia transacción: nunca queda a medias.
- `totals_mismatch` se persiste tal cual para que F3 lo convierta en hallazgo.
- Estados finales: `completed` si todo se guardó o se saltó, `partial` si algo
  falló y otros se guardaron, `failed` (`import_failed`) si no se guardó nada.
- **`ParseSriRun` deja el run en `parsing`**: el veredicto final lo da la
  importación, que es la que sabe cuántos comprobantes quedaron guardados.

## 5. Ejecución en cola y lotes (F2-10)

Cuando un run queda en `parsing`, la importación se encola con
`ProcessSriRunJob` en la cola `sri`:

- El callback firmado encola el job después del commit, con el tenant del run.
- `sri:reconcile-runs` encola un job por cada run que la reconciliación deja
  en `parsing`.
- `ShouldBeUnique` por `run_id` evita duplicados entre callback, scheduler y
  reintentos. El job no hace nada si el run ya no está en `parsing`.
- `TenantAware` reactiva el tenant en el worker y el `TenantScope` impide leer
  un run de otro tenant.

`ProcessSriRunJob` no importa comprobantes: ejecuta `ParseSriRun` y reparte
cada lote de período/tipo en bloques de `SRI_SYNC_CHUNK_SIZE` claves (25 por
defecto). Cada bloque es un `ImportSriChunkJob`, de modo que ningún job
concentra un período completo.

- El batch usa `allowFailures()`: un fallo por comprobante no cancela el resto
  de los bloques.
- Un bloqueo `sri:import:{runId}` (TTL 1 h) impide que un callback tardío o la
  reconciliación periódica abran un segundo batch mientras el run siga en
  `parsing`; el finalizador lo libera.
- Cada comprobante guarda su resultado en `sri_sync_run_files`:
  `status` (`processed`, `missing`, `invalid`), `error_code`
  (`sri_unavailable`, `invalid_xml`, `unexpected_error`), `import_outcome`
  (`created`, `updated`, `skipped`) y `source` (`s3`, `sri`). Reintentar un
  bloque es seguro porque la escritura es idempotente.
- `FinalizeSriRunJob` cierra el run desde las filas persistidas, no desde la
  memoria del proceso: `completed` si todo se guardó, `partial` con
  `error_code=partial_import` si hubo fallos y algo se guardó, y `failed` con
  `error_code=import_failed` si no se guardó nada. El cierre es idempotente.
- Reintentos: `SRI_SYNC_IMPORT_TRIES` (3), backoff 30/120/300 s. Al agotarse,
  el run se cierra como `failed` con `error_code=import_failed`.
- `sri:import-run` sigue disponible para ejecutar la importación de forma
  manual y síncrona, con los mismos bloques y el mismo cierre idempotente.

### Runs huérfanos o atascados (F2-10c)

`ReconcileSriRuns` cierra los runs activos que llevan más de
`SRI_SYNC_STALE_AFTER_MINUTES` (30) sin avanzar. El latido es `updated_at`:
se refresca con cada transición y con cada archivo recuperado desde S3.

- `invoking` / `downloading`: se intenta `RecoverSriRunFromS3`. Si el robot ya
  publicó el paquete, el run sigue a `parsing` y se encola la importación; si
  no hay nada, se cierra como `failed` con `error_code=robot_timeout`.
- `parsing`: se cierra desde las filas persistidas con `FinalizeSriRun`. Si no
  hay ningún comprobante guardado, el run queda `failed` con
  `error_code=import_timeout`. Si el bloqueo `sri:import:{runId}` sigue tomado,
  el batch está en curso y el run no se toca.
- Si una misma reconciliación cierra más de
  `SRI_SYNC_ORPHAN_ALERT_THRESHOLD` (3) runs huérfanos, se registra un
  `Log::warning` con los `run_ids` para disparar la alerta operativa.

El bloqueo se comprueba intentando adquirirlo, no con `Cache::has()`: la caché
multi-tenant prefija las claves normales por tenant, pero los locks se guardan
con el nombre literal. El cierre usa `forceRelease()` por el mismo motivo.

## 6. Límites y cuotas (F2-11)

`StartSriSync` aplica tres controles antes de tocar la Lambda:

| Control | Clave | Comportamiento |
|---|---|---|
| Serialización por empresa | Bloqueo de caché `sri:start:{companyId}` | Dos solicitudes simultáneas de la misma empresa se ejecutan en serie; si no se obtiene el bloqueo en 5 s → `SriSyncAlreadyRunning` |
| Un run activo por empresa | Consulta a `sri_sync_runs` | Si existe un run en `queued`, `invoking`, `downloading` o `parsing` con otra clave de idempotencia → `SriSyncAlreadyRunning` (incluye el `public_id` del run) |
| Cuota por RUC | `RateLimiter` con clave `sri:sync:{ruc}` y ventana de 60 s | Reserva una llamada por cada run nuevo; al superar `sri.sync.max_calls_per_minute` → `SriRateLimitExceeded` con `retryAfterSeconds` |

Reglas:

- Los reintentos con la misma clave de idempotencia **no consumen cuota** y
  devuelven los runs existentes sin invocar la Lambda.
- La cuota es por RUC, no por empresa ni por tenant: protege la cuenta del
  contribuyente en el portal del SRI.
- No se usa un índice único parcial en la base de datos porque una sola
  solicitud puede crear varios runs legítimos cuando el rango supera
  `max_days_per_run`; la exclusión se hace por conjunto de claves.
- `SriRateLimitExceeded` y `SriSyncAlreadyRunning` son excepciones de dominio:
  la capa HTTP puede mapearlas a `429` y `409` respectivamente.

## 7. Variables de entorno

```dotenv
SRI_AUTHORIZATION_DRIVER=package   # package | fake
SRI_AUTHORIZATION_FALLBACK_TO_SRI=true   # dentro de la ventana: SRI primero y S3 como respaldo
SRI_AUTHORIZATION_WINDOW_DAYS=45         # días desde la emisión en que el SRI responde bien
SRI_ENVIRONMENT=1                  # 1 pruebas, 2 producción
SRI_CONNECTION_TIMEOUT=10
SRI_TIMEOUT=30
SRI_RETRY_ATTEMPTS=3
SRI_RETRY_BASE_DELAY_MS=250
SRI_RETRY_MAX_DELAY_MS=5000

SRI_SYNC_MAX_DAYS_PER_RUN=93       # días máximos por invocación del robot
SRI_SYNC_MAX_CALLS_PER_MINUTE=6    # invocaciones por RUC en una ventana de 60 s
SRI_SYNC_IMPORT_QUEUE=sri          # cola del job de importación
SRI_SYNC_IMPORT_TRIES=3            # reintentos antes de cerrar el run como failed
SRI_SYNC_IMPORT_TIMEOUT=90         # segundos por intento
SRI_SYNC_CHUNK_SIZE=25             # claves por lote de importación
SRI_SYNC_STALE_AFTER_MINUTES=30    # minutos sin avance antes de considerar huérfano un run
SRI_SYNC_ORPHAN_ALERT_THRESHOLD=3  # runs huérfanos en una pasada que disparan alerta
REDIS_QUEUE_RETRY_AFTER=180        # debe superar el timeout del job

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

## 8. Pruebas

| Archivo | Qué prueba |
|---|---|
| `tests/Unit/Sri/PackageSriAuthorizationGatewayTest.php` | Autorizado, rechazado, PPR, sin respuesta, fallo de transporte y sobre SOAP real (`tests/Fixtures/response.xml`) |
| `tests/Unit/Sri/SriRobotRequestTest.php` | Payload exacto, deduplicación, validaciones y sobre sellado |
| `tests/Unit/Sri/SriRobotCredentialsTest.php` | Contenido del sobre, `iat` y redacción de la contraseña |
| `tests/Unit/Sri/LambdaSriRobotGatewayTest.php` | Payload de invocación, modo `Event`, `RequestId`, sellado y error AWS |
| `tests/Unit/Sri/SriRobotObjectPathTest.php` | Layout real, slugs, períodos diarios y validaciones |
| `tests/Unit/Sri/SriSyncWindowTest.php` | Agrupación, corte exacto de 93 días y rangos sin huecos |
| `tests/Unit/Sri/SriSyncRunStatusTest.php` | Transiciones permitidas, estados terminales y saltos inválidos |
| `tests/Feature/Sri/StartSriSyncTest.php` | Creación, idempotencia, división, fallo, cancelación, run solapado, límite por minuto, cuota por RUC y ventana configurable |
| `tests/Feature/Sri/ReconcileSriRunTest.php` | Manifiesto/TXT/claves, faltantes, JSON inválido, bytes, identidad, idempotencia, multiperíodo, comando y tenant por defecto |
| `tests/Feature/Sri/SriRobotObjectStoreTest.php` | Lectura, existencia, SHA-256 y objeto ausente en el disco `sri` |
| `tests/Feature/Sri/SriServiceProviderTest.php` | Selección de adaptadores por configuración |
| `tests/Unit/Sri/SriAccessKeyTest.php` | Estructura, tipos, verificador (módulo 11) y claves mal formadas |
| `tests/Unit/Sri/AccessKeyListParserTest.php` | TXT con BOM/CRLF, líneas inválidas, duplicados y cruce con el JSON de claves |
| `tests/Feature/Sri/ParseSriRunTest.php` | Run `completed`/`partial`/`failed`, marca de archivos, idempotencia y comando |
| `tests/Unit/Sri/ComprobanteReaderIntegrationTest.php` | Los seis tipos por el adaptador: totales, ítems, impuestos, retenciones, XXE, tamaño y XML malformado |
| `tests/Feature/Sri/PersistSriInvoiceTest.php` | Creación, hijos, idempotencia, actualización, aislamiento por empresa y retenciones |
| `tests/Feature/Sri/ImportSriRunTest.php` | Importación completa, reimportación sin duplicados, `partial`, `failed`, XML corrupto y comando |
| `tests/Feature/Sri/ProcessSriRunJobTest.php` | Reparto en lotes según `chunk_size`, importación en cola de extremo a extremo, run fuera de `parsing`, run inexistente, unicidad y cierre tras agotar reintentos |
| `tests/Feature/Sri/ImportSriChunksTest.php` | Resultado por comprobante, fallos aislados (`invalid_xml`, `sri_unavailable`), lectura desde S3 y job de bloque |
| `tests/Feature/Sri/FinalizeSriRunTest.php` | Cierre `completed`/`partial`/`failed` desde filas persistidas, limpieza de errores, idempotencia y job de cierre |
| `tests/Feature/Sri/ResolveStaleSriRunTest.php` | Run sin avanzar, `robot_timeout` en `invoking`/`downloading`, reanudación desde S3, `import_timeout` en `parsing`, respeto del bloqueo de importación, alerta de huérfanos e integración con la reconciliación periódica |
| `tests/Feature/Sri/SriRunMetricsTest.php` | Cálculo de duración y volumen desde filas persistidas, niveles de log por resultado y ausencia de duplicados al re-guardar un run cerrado |
| `tests/Feature/Sri/SriMetricsCommandTest.php` | Agregación de runs por ventana, duración media/p95, volumen y tasa de fallo; salida vacía |
| `tests/Feature/Sri/ImportSriRunFromS3Test.php` | XML desde S3 sin consultar el SRI, respaldo por clave, `fallback_to_sri=false`, período ajeno, RUC ajeno, `missing`, período vacío, ventana de 45 días y `sri:recover-run` |

No hay red en la suite: el transporte SOAP y el cliente Lambda se sustituyen
por dobles.

## 9. Prueba end-to-end en local

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

## 10. Siguientes pasos

1. **F2-10:** pipeline asíncrono en cola, lotes de comprobantes, reintentos y
   finalización de runs huérfanos.
2. **F2-09:** progreso en vivo y cancelación de la invocación asíncrona, después
   de F1-08 y de la rebanada mínima de identidad (F1-03).
3. **F2-12:** eventos `sri_run_events`, logs estructurados y métricas.
4. **F1-05/F1-06:** CRUD de empresas y almacenamiento cifrado de credenciales.
5. **Rendimiento:** medir 5.000 XML con `ComprobanteReader` y, si hace falta,
   paralelizar por lotes.

## 11. Seguridad

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
