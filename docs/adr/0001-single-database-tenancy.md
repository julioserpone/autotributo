# ADR-0001: Multitenancy con base de datos única y `tenant_id`

- **Estado:** aceptado (sujeto a validación en F0-03); **revisado por ADR-0006** (arranque single-tenant)
- **Fecha:** 2026-10-22
- **Decisores:** responsable técnico, backend, contador asesor

> **Revisión 2026-10-22 (ADR-0006):** la decisión de **una sola base de datos con `tenant_id`** se mantiene. El MVP arranca en modo `single` (un estudio por despliegue) y el modo multi se activa después sin migrar datos. No se usará `CREATE DATABASE` ni `CREATE SCHEMA` en runtime.

## Contexto

Autotributo es un SaaS para estudios contables. Cada estudio (tenant) administra varios contribuyentes (RUC) y debe existir aislamiento estricto entre estudios. El despliegue objetivo es Laravel Cloud con Serverless Postgres (Neon), donde:

- Un entorno se adjunta a **una** base de datos mediante `DB_DATABASE`.
- Las bases de datos se crean por panel/API, hasta 500 por clúster, no por SQL de la aplicación (a validar).
- Neon escala a cero y cada base adicional multiplica arranques en frío, backups y operaciones.
- Las migraciones se ejecutan en el deploy command; N bases implican N ejecuciones.

El plan original proponía `stancl/tenancy:dev-master` con la posibilidad de BD por tenant.

## Decisión

1. **Una sola base de datos, esquema compartido**, con `tenant_id` obligatorio en toda tabla de negocio.
2. Resolución del tenant por **subdominio** (`acme.autotributo.com`); cabecera `X-Tenant` solo para clientes API autenticados con token. **En el MVP single-tenant se usa el tenant por defecto y el subdominio queda reservado (ADR-0006).**
3. `stancl/tenancy` se usa para modelo de tenant/dominios, ciclo de vida y contexto en jobs, caché y almacenamiento. Se **desactiva** el bootstrapper de base de datos.
4. Implementación propia del scoping: trait `BelongsToTenant` + `TenantScope` global + `tenant_id` forzado al crear.
5. **Versión fijada `stancl/tenancy:^3.10`**, no `dev-master`.
6. Aislamiento garantizado por: scope global, suite de tests de acceso cruzado en CI, revisión obligatoria en PR y, en Fase 6, RLS de PostgreSQL como defensa en profundidad.

## Consecuencias

**Positivas**
- Funciona sin fricción en Laravel Cloud (un recurso, un deploy, un backup).
- Consultas de soporte, métricas y mantenimiento simples.
- Coste y latencia predecibles; sin fan-out de arranques en frío.
- Migraciones y seeds una sola vez.

**Negativas**
- El aislamiento depende de disciplina de código; un `where` olvidado es un incidente grave.
- Un tenant muy grande puede afectar a los demás (noisy neighbor).
- Restaurar un solo tenant requiere export/import lógico, no PITR selectivo.
- RLS no es trivial con pooling y migraciones; requiere spike propio.

**Mitigaciones**
- `TenantScope` global y prohibición de `DB::table()` sin `tenant_id`.
- Tests de aislamiento por endpoint y por recurso; se ejecutan en cada PR.
- Índices compuestos que empiezan por `tenant_id`.
- Límites de uso por tenant y monitoreo de consultas lentas.

## Alternativas consideradas

| Alternativa | Por qué no |
|---|---|
| BD por tenant | Creación y migración en runtime no encajan con Laravel Cloud; 500 BD máximo por clúster; backups y costes se multiplican; migraciones N veces |
| Esquema por tenant | Requiere `CREATE SCHEMA` en runtime (a validar), miles de relaciones por base, restauración y migraciones más complejas; sin beneficio claro para el MVP |
| Schema-per-tenant + RLS | Complejidad alta para un equipo pequeño; se puede adoptar si un cliente exige aislamiento físico |

## Ruta de migración si se requiere aislamiento dedicado

1. Añadir un "tenant group" o `cluster` que apunte a otra conexión (`tenant_{id}`).
2. Mover datos con export/import por tenant y doble escritura temporal.
3. Mantener el mismo contrato de repositorios para no cambiar el dominio.
4. Documentar el caso enterprise y su coste operativo antes de comprometerlo comercialmente.

## Validación pendiente (F0-03)

- Confirmar permisos del rol inyectado por Laravel Cloud (`CREATE DATABASE`/`CREATE SCHEMA`).
- Medir arranque en frío y latencia con el endpoint *pooled*.
- Verificar comportamiento de `SET LOCAL` con el pooling de Neon antes de activar RLS.
