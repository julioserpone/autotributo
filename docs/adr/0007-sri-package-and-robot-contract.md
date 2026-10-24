# ADR 0007: paquete SRI y contrato real del robot

- **Estado:** aceptado
- **Fecha:** 2026-10-22

## Contexto

F2 necesita dos integraciones distintas:

1. Consultar el XML autorizado de un comprobante a partir de su clave de acceso.
2. Pedir al robot `sri-txt-robot` que descargue los reportes TXT del portal del SRI.

El repositorio `julioserpone/sri-comprobantes-php` implementa la primera. La
segunda vive en un proyecto separado (`sri-txt-robot`) y no forma parte del
paquete PHP.

La versión estable publicada en GitHub es `v0.1.2`. La rama local
`feature/consulta-masiva-txt` contiene `ConsultaMasivaService` y
`TxtReportParser`, pero **no está publicada en GitHub ni tiene un tag**. El
robot, además, ya entrega un JSON con las claves de acceso, por lo que el
parser TXT no es imprescindible para la primera integración.

## Decisión

1. Instalar el paquete por repositorio VCS fijado a `^0.1` (resuelto hoy a
   `v0.1.2`), no por `path` local. Así el mismo `composer.lock` funciona en
   Laravel Cloud.
2. Exponer dos puertos de dominio independientes:
   - `SriAuthorizationGateway` para consultar una clave y normalizar el estado.
   - `SriRobotGateway` para despachar el robot.
3. Implementar la consulta con `PackageSriAuthorizationGateway`, que adapta
   `AutorizacionClient` y los DTO del paquete. El dominio no conoce SOAP.
4. Implementar el robot con `LambdaSriRobotGateway`, invocando la Lambda en
   modo `Event` y enviando el payload real del handler:
   `{ruc, periodos[], tipos[], forzar}`.
5. Mantener adaptadores `Fake...` para desarrollo y pruebas. Nunca se llama a
   AWS ni al SRI en la suite.
6. Agregar `ext-soap` a la imagen local y documentarlo como requisito del
   runtime de Laravel Cloud.

## Contrato real del robot

El handler de `sri-txt-robot` acepta este evento:

```json
{
  "ruc": "1758871873001",
  "periodos": ["2026-08"],
  "tipos": ["01", "04"],
  "forzar": false,
  "sealed_credentials": "base64(sodium_crypto_box_seal({...}))"
}
```

- La app usa el modo dinámico: sella `{ruc, password, claveAdicional, iat}`
  con la clave pública del robot. La contraseña no viaja en claro.
- La clave privada vive en `SRI_CREDENTIALS_PRIVATE_KEY` de la Lambda, no en
  AWS Secrets Manager. El modo estático queda como respaldo.
- El bucket y el prefijo S3 también se configuran en Lambda.
- El robot escribe TXT, un JSON de claves y un `manifest.json` bajo su propia
  jerarquía `{ambiente}/{ruc}/{periodo}/`.
- **El handler actual no envía callback HTTP.** Por tanto, F2-04 (callback
  firmado) no puede cerrarse sin modificar el robot o usar otro mecanismo de
  notificación. La reconciliación por S3 sigue siendo la fuente de verdad.

## Consecuencias

- La app puede consultar XML y despachar el robot desde el primer día.
- `composer.lock` es reproducible y el despliegue no depende de una rama local.
- La consulta masiva se orquesta en Laravel (jobs y límites), no en el paquete.
- F2-04 queda explícitamente bloqueado hasta acordar el callback con el robot.
- Cuando se publique una versión con `ConsultaMasivaService`/`TxtReportParser`,
  se podrá sustituir la orquestación propia sin cambiar los puertos.

## Alternativas descartadas

- **Usar `dev-main` o una rama local:** no es reproducible en Cloud y no aporta
  nada para la consulta por clave.
- **Copiar el paquete dentro de la app:** duplica mantenimiento y oculta la
  frontera de integración.
- **Llamar al SRI desde jobs o controladores directamente:** acopla el dominio
  a SOAP y dificulta los tests de contrato.
- **Implementar ya el callback:** el robot actual no lo emite; sería un
  contrato ficticio.
