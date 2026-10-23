# 10 — Integración SRI

> Alcance: F2-01, F2-02 y la base de F2-03. El paquete consulta el XML por
> clave de acceso, el robot descarga los reportes, la app lee su bucket S3 y
> los runs de sincronización se orquestan con idempotencia. El callback, la
> reconciliación, el parser XML y la persistencia de comprobantes llegan en
> F2-04 a F2-08.

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

La versión resuelta es `v0.1.2`, que incluye:

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
│   └── StartSriSync.php              # crea y despacha runs idempotentes
├── Contracts/
│   ├── SriAuthorizationGateway.php   # XML por clave
│   └── SriRobotGateway.php           # descarga de TXT
├── Data/
│   ├── AuthorizationQuery.php
│   ├── AuthorizationResult.php
│   ├── SriMessage.php
│   ├── SriRobotCredentials.php       # sobre que se sella
│   ├── SriRobotRequest.php
│   ├── SriRobotDispatch.php
│   ├── SriSyncBatch.php              # runs de una solicitud
│   └── SriSyncWindow.php             # agrupación y corte de 93 días
├── Enums/
│   ├── AuthorizationStatus.php
│   └── SriSyncRunStatus.php
└── Models/
    └── SriSyncRun.php
```

Adaptadores de infraestructura:

| Clase | Uso |
|---|---|
| `PackageSriAuthorizationGateway` | Consulta SOAP real con el paquete |
| `LambdaSriRobotGateway` | Invoca `sri-txt-robot` en AWS Lambda (`Event`) |
| `FakeSriAuthorizationGateway` | Desarrollo y tests sin red |
| `FakeSriRobotGateway` | Desarrollo y tests sin AWS |

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
- El robot actual **no** envía callback HTTP. La app debe reconciliar contra
  S3 o esperar a que el robot incorpore el callback (ver ADR 0007).

Salida esperada del robot en su bucket:

```text
{ambiente}/{ruc}/{periodo}/facturas-recibidas-{periodo}.txt
{ambiente}/{ruc}/{periodo}/facturas-recibidas-{periodo}-claves.json
{ambiente}/{ruc}/{periodo}/manifest.json
```

`SriRobotObjectPath` construye estas claves en el dominio; el prefijo opcional
del bucket lo aplica el disco `sri` de Laravel (`SRI_ROBOT_OUTPUT_PREFIX`). El
robot **no** publica checksums en el manifiesto actual: la app calcula SHA-256
al leer (`SriRobotObjectStore::sha256`) y lo persistirá en
`sri_sync_run_files` cuando exista F2-03/F2-08.

### Lectura desde Laravel

| Pieza | Responsabilidad |
|---|---|
| `SriRobotObjectPath` | Genera claves estables para TXT, JSON de claves y manifiesto |
| `SriRobotObjectStore` | Contrato de existencia, lectura y SHA-256 |
| `FilesystemSriRobotObjectStore` | Adaptador del disco `sri` (S3 en Cloud) |

La app solo lee el bucket del robot. El rol IAM del robot es el único que
escribe; el usuario de la app no necesita `s3:PutObject` sobre `-sri`.

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
  F2-04/F2-09.
- La tabla `companies` se incorporó con lo mínimo para la FK del run; el CRUD
  completo y la validación de RUC pertenecen a F1-05.
- `sri_sync_run_files` y la reconciliación por S3 llegan en F2-05/F2-08.

## 4. Variables de entorno

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
SRI_ROBOT_REGION=us-east-1
SRI_ROBOT_ENVIRONMENT=dev          # dev | test | produccion (debe coincidir con la Lambda)
SRI_ROBOT_OUTPUT_BUCKET=autotributo-local-sri
SRI_ROBOT_OUTPUT_PREFIX=           # prefijo opcional; vacío por defecto
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

## 5. Pruebas

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
| `tests/Feature/Sri/SriRobotObjectStoreTest.php` | Lectura, existencia, SHA-256 y objeto ausente en el disco `sri` |
| `tests/Feature/Sri/SriServiceProviderTest.php` | Selección de adaptadores por configuración |

No hay red en la suite: el transporte SOAP y el cliente Lambda se sustituyen
por dobles.

## 6. Siguientes pasos

1. **F2-04:** callback firmado. Requiere modificar `sri-txt-robot` para emitir
   `event_id`, timestamp y HMAC; hoy no existe.
2. **F2-05:** reconciliación por S3 usando `manifest.json` y los JSON de claves.
3. **F2-06 a F2-08:** parser XML, persistencia idempotente y fixtures.
4. **F1-05/F1-06:** CRUD de empresas y almacenamiento cifrado de credenciales;
   `StartSriSync` ya acepta el sobre, pero la app aún debe leerlo de la base.

## 7. Seguridad

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
