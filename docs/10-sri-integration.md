# 10 — Integración SRI

> Alcance: F2-01 y F2-02. El paquete consulta el XML por clave de acceso, el
> robot descarga los reportes y la app lee su bucket S3. La persistencia y el
> parser XML llegan en F2-03 a F2-08.

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
├── Contracts/
│   ├── SriAuthorizationGateway.php   # XML por clave
│   └── SriRobotGateway.php           # descarga de TXT
├── Data/
│   ├── AuthorizationQuery.php
│   ├── AuthorizationResult.php
│   ├── SriMessage.php
│   ├── SriRobotRequest.php
│   └── SriRobotDispatch.php
└── Enums/AuthorizationStatus.php
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
  "forzar": false
}
```

Reglas:

- `periodos` acepta `YYYY-MM` o `YYYY-MM-DD`.
- `tipos` usa los códigos de la ficha técnica; una lista vacía deja que el
  robot use los tipos configurados en su YAML.
- Las credenciales del portal y el bucket S3 viven en la Lambda, nunca en el
  payload de Laravel.
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
```

En local, `SRI_AUTHORIZATION_DRIVER=fake` evita golpear el SRI y
`SRI_ROBOT_DRIVER=fake` evita AWS. En staging/producción se usa `package` y
`lambda`.

## 5. Pruebas

| Archivo | Qué prueba |
|---|---|
| `tests/Unit/Sri/PackageSriAuthorizationGatewayTest.php` | Autorizado, rechazado, PPR, sin respuesta y fallo de transporte |
| `tests/Unit/Sri/SriRobotRequestTest.php` | Payload exacto, deduplicación y validaciones |
| `tests/Unit/Sri/LambdaSriRobotGatewayTest.php` | Payload de invocación, modo `Event`, `RequestId` y error AWS |
| `tests/Unit/Sri/SriRobotObjectPathTest.php` | Layout real, slugs, períodos diarios y validaciones |
| `tests/Feature/Sri/SriRobotObjectStoreTest.php` | Lectura, existencia, SHA-256 y objeto ausente en el disco `sri` |
| `tests/Feature/Sri/SriServiceProviderTest.php` | Selección de adaptadores por configuración |

No hay red en la suite: el transporte SOAP y el cliente Lambda se sustituyen
por dobles.

## 6. Siguientes pasos

1. **F2-03:** `SriSyncRun`, `StartSriSync`, división de rangos de 93 días,
   idempotencia y cancelación.
2. **F2-04:** callback firmado. Requiere modificar `sri-txt-robot` para emitir
   `event_id`, timestamp y HMAC; hoy no existe.
3. **F2-05:** reconciliación por S3 usando `manifest.json` y los JSON de claves.
4. **F2-06 a F2-08:** parser XML, persistencia idempotente y fixtures.

## 7. Seguridad

- No registrar XML completos, credenciales ni claves de acceso completas.
- No enviar credenciales en el evento del robot; su configuración vive en la
  Lambda.
- No desactivar TLS ni `verify_peer` en `SoapTransport`.
- Mantener `SRI_CREDENTIALS_KEY` fuera del repositorio y con respaldo en el
  gestor de secretos.
