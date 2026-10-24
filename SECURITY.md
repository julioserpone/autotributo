# Security policy

## Versiones soportadas

| Versión | Soporte |
|---|---|
| `0.1.x` (MVP) | Sí, mientras esté en desarrollo activo |
| `< 0.1` | No |

## Reportar una vulnerabilidad

**No abras un issue público.** Usa una de estas vías privadas:

1. [GitHub Security Advisories](https://github.com/julioserpone/autotributo/security/advisories/new) (recomendado).
2. Mensaje privado al maintainer **julioserpone** en GitHub.

Incluye:

- Descripción del problema y componente afectado.
- Pasos de reproducción mínimos.
- Impacto estimado (confidencialidad, integridad, disponibilidad).
- Versión o commit.
- Prueba de concepto, si es segura de compartir.
- Cómo prefieres que te contactemos.

No incluyas credenciales reales, claves SRI, XML de contribuyentes ni datos personales. Si necesitas demostrar un secreto, usa valores ficticios.

## Qué esperar

- Acuse de recibo en un máximo de **72 horas**.
- Evaluación inicial y severidad en un máximo de **7 días**.
- Actualizaciones periódicas hasta la resolución.
- Crédito en el advisory si lo deseas.

## Alcance

En alcance:

- Autenticación, autorización y aislamiento entre tenants.
- Exposición de credenciales SRI, claves, tokens o secretos.
- Inyección SQL, XSS, SSRF, CSRF y deserialización insegura.
- Ejecución remota de código o escalada de privilegios.
- Fugas de datos personales o fiscales.
- Manipulación de manifiestos, checksums o archivos S3 del robot.
- Dependencias con vulnerabilidades explotables en este contexto.

Fuera de alcance:

- Ataques que requieren acceso físico al dispositivo del usuario.
- Ingeniería social.
- Denegación de servicio volumétrica sin impacto demostrable.
- Reportes generados automáticamente sin reproducción.
- Configuraciones locales inseguras que no afectan a staging/producción.

## Manejo de secretos

- La contraseña SRI se cifra con `sodium_crypto_box_seal` y nunca se persiste ni se muestra en claro.
- La clave privada de la Lambda vive solo en `SRI_CREDENTIALS_PRIVATE_KEY`; la pública en `SRI_ROBOT_PUBLIC_KEY`.
- No se usa AWS Secrets Manager en este flujo.
- Si un secreto se filtra, se **rota**; borrarlo del historial no es suficiente.
- `.env`, `.pem`, `.key`, XML reales y dumps están prohibidos en el repositorio.

## Prácticas seguras

- 2FA obligatorio en GitHub, AWS, Laravel Cloud y proveedor de IA.
- Principio de menor privilegio para IAM y roles de aplicación.
- Revisión de dependencias con `composer audit` y `npm audit`.
- `gitleaks` en CI.
- Datos de producción nunca se copian a local; se anonimizan.
- Logs sin secretos, tokens, claves de acceso completas ni PII.

## Divulgación

Coordinamos la divulgación contigo. Publicaremos un advisory cuando exista una corrección disponible y los sistemas afectados hayan sido actualizados.
