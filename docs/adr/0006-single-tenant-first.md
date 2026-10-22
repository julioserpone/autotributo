# ADR-0006: Arranque single-tenant con esquema preparado para multitenancy

- **Estado:** aceptado
- **Fecha:** 2026-10-22
- **Decisores:** responsable técnico, product owner, contador asesor
- **Relacionado:** ADR-0001 (base de datos única y `tenant_id`)

## Contexto

El MVP se lanzará con **un solo estudio contable por despliegue**. No hay todavía demanda de autoservicio para que cualquier estudio cree su cuenta, ni presupuesto para operar aislamiento multi-estudio desde el día 1. Sin embargo, el producto es un SaaS y la decisión de cómo crecer no debe exigir reescribir el modelo de datos ni los flujos de autorización.

El coste relevante no es el número de filas ni el `tenant_id`: es **crear y migrar una base de datos por cliente**. Ese camino queda descartado desde ADR-0001 (`CREATE DATABASE`/`CREATE SCHEMA` en runtime, backups y arranques en frío multiplicados).

## Decisión

1. **Modo inicial `single`:** un despliegue atiende a un único estudio (tenant). No hay registro público de estudios, ni subdominios por tenant, ni selector de estudio.
2. **Esquema tenant-ready desde la primera migración:** todas las tablas de negocio conservan `tenant_id`, FK e índices compuestos que empiezan por él.
3. **Contexto de tenant explícito:** `stancl/tenancy` se instala desde F1 (sin `DatabaseTenancyBootstrapper`) y un `TenantContext` resuelve el tenant por defecto desde configuración (`TENANCY_MODE=single`, `TENANCY_DEFAULT_SLUG`). Los modelos de negocio usan `BelongsToTenant` + `TenantScope`; los jobs, comandos y eventos pasan por `TenantAware`.
4. **Sin subdominios en el MVP:** la aplicación vive en un dominio único (`app.autotributo.com` o el dominio del estudio). La tabla `domains` y el middleware de resolución por subdominio quedan **reservados** para el modo multi; no se exponen en la UI.
5. **Aislamiento físico:** una sola base de datos por entorno (ADR-0001). No se crean bases por cliente.
6. **El modo multi se activa por configuración y producto, no por migración de datos:** `TENANCY_MODE=multi` habilita `ResolveTenant`, onboarding, dominios e invitaciones entre estudios. El tenant inicial ya existe y no hay que transformar filas.
7. **La suite de aislamiento se mantiene desde el MVP:** se prueban dos tenants en tests aunque la UI solo cree uno. Es la garantía de que el modo multi no heredará fugas de datos.
8. **Provisión inicial por comando**, idempotente y auditable: `app:provision --name=... --slug=... --owner-email=...` crea el tenant por defecto, carga catálogos y envía la invitación del owner. No es un endpoint público.

## Consecuencias

**Positivas**
- Cero coste de bases de datos por cliente y una sola migración por deploy.
- El MVP se enfoca en el flujo fiscal, que es el valor real del producto.
- Activar multitenancy después es un cambio de configuración, middleware y UI; los datos ya están separados por `tenant_id`.
- La auditoría, el storage (`tenants/{tenant_id}/...`) y los logs ya nacen con la dimensión de tenant.
- Menos superficie de ataque: no hay registro público ni enumeración de estudios.

**Negativas**
- Overhead pequeño: el scope global y `tenant_id` existen aunque solo haya una fila.
- La UI no debe “prometer” funciones multi-estudio (cambio de estudio, invitaciones cruzadas, planes por estudio) que aún no existen.
- Hay que mantener la disciplina de no usar consultas sin scope, igual que en modo multi.

**Riesgos y mitigaciones**
- *Olvidar el tenant en una consulta:* tests de aislamiento obligatorios y revisión de PR.
- *Deuda de activación:* el checklist de activación multi (abajo) se revisa en cada fase para que no se degrade.
- *Un cliente enterprise exige aislamiento físico:* ruta de migración documentada en ADR-0001 (tenant group + export/import).

## Qué se difiere (no se elimina)

| Elemento | MVP single | Modo multi |
|---|---|---|
| Registro público de estudios | No | Sí |
| Subdominios (`estudio.autotributo.com`) | No | Sí |
| Tabla `domains` y middleware `ResolveTenant` | Reservados, sin uso | Activos |
| Selector de estudio | No | Sí |
| Membresías en varios estudios | Una por usuario | Varias |
| Planes y facturación por estudio | No | Sí |
| Aislamiento multi-tenant en tests | Sí (readiness) | Sí (obligatorio) |

## Checklist para activar el modo multi (estimado: 3–5 días + UI)

1. Cambiar `TENANCY_MODE=multi` y añadir el middleware de resolución por subdominio/dominio.
2. Implementar el flujo de registro de estudio y la verificación de slug.
3. Añadir el selector de estudio y refresco de sesión al cambiar de tenant.
4. Revisar policies y canales de broadcasting para membresías múltiples.
5. Ejecutar la suite de aislamiento completa y una prueba de carga con ≥ 10 tenants.
6. Revisar límites de uso, cuotas y métricas por tenant.
7. Actualizar la documentación de soporte y los runbooks.

## Alternativas consideradas

| Alternativa | Por qué no |
|---|---|
| Multitenancy completo desde el MVP | Añade onboarding, dominios, planes, soporte y pruebas de aislamiento sin demanda validada; retrasa el flujo fiscal |
| No incluir `tenant_id` hasta necesitarlo | Migración dolorosa de todas las tablas, índices, jobs, storage y logs; riesgo alto de fugas al introducirlo después |
| Una base de datos por cliente desde el inicio | Coste y operación multiplicados; Laravel Cloud adjunta una BD por entorno y las migraciones en runtime son frágiles |
| Despliegue separado por cliente (instancia dedicada) | Duplica infraestructura y operación; solo tendría sentido para un cliente enterprise con requisitos de aislamiento físico |

## Criterio de revisión

Se reevalúa esta decisión cuando ocurra cualquiera de estos disparadores:

- Hay un segundo estudio real que necesita usar el producto.
- Se firma un contrato que exige autoservicio o subdominio propio.
- El soporte necesita consultar datos de más de un estudio desde una sola sesión.
