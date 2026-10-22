# 06 — Despliegue en Laravel Cloud

## 1. Topología de entornos

| Entorno | Propósito | Datos | Deploy |
|---|---|---|---|
| `preview` | Por Pull Request | Sintéticos/seed | Automático al abrir PR (opcional) |
| `staging` | Integración y UAT | Copia anonimizada | Automático al mergear a `main` |
| `production` | Clientes | Reales | Manual (promoción de tag) con aprobación |

Cada entorno tiene su propia base de datos, bucket, colas y credenciales. **Nunca** se comparten secretos entre staging y producción.

## 2. Recursos por entorno

| Recurso | Configuración |
|---|---|
| App cluster | PHP 8.5, mínimo 1 instancia en producción (2 en beta con tráfico), autoescalado |
| Managed queue | 3 colas: `sri` (Flex 512 MB), `ai` (Flex 512 MB), `reports` (Pro 1 GB) |
| Base de datos | Serverless Postgres (Neon), misma región, PITR 7–30 días |
| Caché | Valkey (sesiones, caché, locks) |
| Object Storage | **AWS S3 en cuenta propia**, bucket por entorno: `autotributo-prod-app` + `autotributo-prod-sri`; `autotributo-staging-app` + `autotributo-staging-sri` |
| WebSockets | Cluster Reverb administrado, aplicación `main` |
| Email | Resend |
| Log drain | Sentry / proveedor de logs |

> El filesystem del contenedor es **efímero y por réplica**. Todo lo persistente va a Object Storage.

## 3. Build y deploy

**Build commands** (se ejecutan en cada deploy, con el código congelado):

```bash
composer install --no-dev --prefer-dist --no-interaction --no-progress
npm ci --no-audit --no-fund
npm run build
php artisan optimize
```

**Deploy commands** (se ejecutan antes de activar la nueva versión):

```bash
php artisan migrate --force --isolated
```

Notas:
- `php artisan optimize` en build (config/route/view cacheados dentro del artefacto), **no** en deploy.
- No ejecutar `optimize:clear`, `storage:link` ni `queue:restart` en el deploy: Cloud gestiona workers y reinicios.
- `--isolated` usa un lock en caché; evita migraciones concurrentes durante el rolling deploy.
- Los cambios de variables de entorno requieren **redeploy** (la config está cacheada).

## 4. Variables de entorno

| Variable | Ejemplo producción | Secreto | Notas |
|---|---|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` | — | `APP_DEBUG=true` jamás en producción |
| `APP_KEY` | `base64:...` | ✅ | Generada una vez; rotarla invalida sesiones |
| `APP_URL` | `https://app.autotributo.com` | — | |
| `TENANCY_MODE` | `single` | — | `single` en el MVP; `multi` solo al activar ADR-0006 |
| `TENANCY_DEFAULT_SLUG` | `estudio` | — | Slug del estudio provisionado |
| `APP_ROOT_DOMAIN` | `autotributo.com` | — | Reservado para el modo multi; no se usa en single |
| `DB_*` | inyectadas por Cloud | ✅ | No sobrescribir |
| `CACHE_STORE` | `redis` | — | Valkey |
| `SESSION_DRIVER` | `redis` | — | |
| `QUEUE_CONNECTION` | `cloud` | — | Lo define Cloud al crear la cola administrada |
| `FILESYSTEM_DISK` | `s3` | — | En Cloud siempre S3; en local es `local` por defecto |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | usuario IAM del entorno | ✅ | Credenciales de **tu cuenta AWS**, nunca de root; un usuario por entorno |
| `AWS_DEFAULT_REGION` | `us-east-1` | — | La misma región del bucket |
| `AWS_BUCKET` | `autotributo-prod-app` | — | Bucket de la app; **nunca** el de staging |
| `AWS_ENDPOINT` | *(vacío)* | — | Vacío para AWS real; no usar endpoint local en Cloud |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `false` | — | AWS S3 usa virtual-hosted style |
| `SRI_ROBOT_DRIVER` | `lambda` | — | |
| `SRI_ROBOT_FUNCTION` | `sri-txt-robot-prod` | — | |
| `SRI_ROBOT_OUTPUT_BUCKET` | `autotributo-prod-sri` | — | Bucket del robot, separado del de la app |
| `SRI_ROBOT_CALLBACK_SECRET` | aleatorio 32+ bytes | ✅ | HMAC del webhook |
| `SRI_ROBOT_PUBLIC_KEY` | base64 | — | Clave pública para sellar credenciales |
| `SRI_CREDENTIALS_KEY` | base64 32 bytes | ✅ | Cifra credenciales SRI; guardar copia en el gestor de secretos de la organización |
| `LLM_DRIVER` / `LLM_MODEL` | `openai` / `gpt-...` | — | |
| `OPENAI_API_KEY` | `sk-...` | ✅ | Presupuesto y límite de gasto configurados en el proveedor |
| `LLM_MONTHLY_BUDGET_USD` | `200` | — | Corta la clasificación automática al alcanzarlo |
| `REVERB_*` | inyectadas | ✅ | Las define Cloud al adjuntar WebSockets |
| `SENTRY_LARAVEL_DSN` | `https://...` | ✅ | |
| `MAIL_*` | inyectadas por Resend | ✅ | |
| `LOG_CHANNEL` | `stderr` | — | Cloud captura stdout/stderr |

**Reglas:**
- Secretos solo en Cloud Secrets o en el gestor de la organización; nunca en el repo.
- Separar credenciales AWS por entorno (IAM user/role distinto).
- Rotación trimestral de `SRI_ROBOT_CALLBACK_SECRET`, llaves de AWS y API keys de LLM.
- `SRI_CREDENTIALS_KEY` tiene rotación con `key_version`; nunca se borra la versión anterior hasta migrar todos los registros.

## 5. Colas

Mapeo de colas locales a Cloud: `sri`, `ai`, `reports` (+ `default`). Cada nombre debe existir como cola administrada en el entorno; Cloud asigna `QUEUE_CONNECTION=cloud`.

| Cola | Clase | Memoria | Timeout de negocio | Reintentos |
|---|---|---|---|---|
| `sri` | Flex | 512 MB | ≤ 60 s por job | 3, backoff 10/60/300 s |
| `ai` | Flex | 512 MB | ≤ 45 s | 2 |
| `reports` | Pro | 1 GB | ≤ 10 min (PDF grande) | 1 |
| `default` | Flex | 256 MB | ≤ 30 s | 3 |

Reglas específicas de SQS/Cloud:
- **Payload ≤ 1 MiB.** Los jobs reciben `run_id`, `invoice_id`, `s3_key`; nunca XML ni PDFs.
- **At-least-once.** Toda escritura es idempotente (upsert por clave natural).
- **Delay máximo 15 min** en colas estándar. Para reprogramaciones mayores, usar el Scheduler.
- **Sin Horizon.** Los fallos se gestionan en el dashboard de Queues o vía API de Cloud; reintentos de negocio se implementan en el dominio.
- Un job que supera el timeout de Flex (90 s) se reintenta: si el trabajo es mayor, se divide en unidades.

Ejemplo de configuración de reintentos en el job:

```php
public int $tries = 3;
public int $timeout = 60;
public array $backoff = [10, 60, 300];
public bool $failOnTimeout = true;

public function retryUntil(): DateTime
{
    return now()->addHours(6);
}
```

## 6. Scheduler

Activar el **Scheduler** en el App cluster (o en un worker dedicado). Cloud ejecuta `schedule:run` cada minuto.

```php
Schedule::command('sri:reconcile-runs')->everyTenMinutes()->onOneServer()->withoutOverlapping();
Schedule::command('invoices:refresh-period-aggregates')->hourly()->onOneServer();
Schedule::command('fiscal:scan-risks')->dailyAt('03:00')->onOneServer();
Schedule::command('catalogs:check-updates')->weeklyOn(1, '05:00')->onOneServer();
Schedule::command('ai:evaluate')->weeklyOn(1, '06:00')->onOneServer();
Schedule::command('backup:logical-dump')->dailyAt('02:00')->onOneServer();
Schedule::command('exports:purge-expired')->dailyAt('04:00')->onOneServer();
```

- `onOneServer()` obligatorio si hay más de una réplica.
- Las tareas programadas **despiertan** el entorno si está en scale-to-zero.
- Zona horaria del scheduler: `UTC`; las fechas de vencimiento del SRI se calculan en `America/Guayaquil` explícitamente.

## 7. Base de datos y backups

- Postgres serverless (Neon): PITR configurado con retención de 7–30 días según criticidad.
- **Backup lógico diario** a S3 (`pg_dump` comprimido y cifrado) con retención de 30 días + copia mensual de 12 meses. Motivo: PITR protege del error humano reciente; el dump protege de corrupción prolongada y permite exportar/restaurar por tenant.
- Restauración ensayada **trimestralmente** y antes del go-live, midiendo RTO real.
- Conexiones: usar el endpoint *pooled* para la app; migraciones y dumps por conexión directa. Configurar `DB_POOL`/timeouts del driver.
- Migraciones: expand/contract (ver `03-data-model.md`). Una migración destructiva nunca viaja en el mismo release que el código que deja de usar la columna.

## 8. Object Storage (AWS S3)

Se usa **tu cuenta de AWS**, con un bucket por entorno y por función. La guía operativa (creación, IAM, lifecycle, verificación) está en [`aws-s3-setup.md`](aws-s3-setup.md).

| Ambiente | Bucket de la app | Bucket del robot |
|---|---|---|
| Local (opcional) | `autotributo-local-app` | `autotributo-local-sri` |
| Test / integración | `autotributo-test-app` | `autotributo-test-sri` |
| Staging | `autotributo-staging-app` | `autotributo-staging-sri` |
| Producción | `autotributo-prod-app` | `autotributo-prod-sri` |

En local, por defecto se usa **disco** (`FILESYSTEM_DISK=local`); los tests usan disco o `Storage::fake()`. AWS S3 real en local es opcional y solo con el bucket `autotributo-local-*`.

**Preview environments:** si necesitan storage persistente, usan `autotributo-test-app` con el prefijo `preview/pr-{n}/`; nunca el bucket de staging o producción. La guarda de ambiente permite el mapeo `preview → test`.

Prefijos:

```
tenants/{tenant_id}/companies/{company_id}/invoices/{yyyy}/{mm}/{access_key}.xml.gz
tenants/{tenant_id}/declarations/{declaration_id}/v{n}.pdf|xlsx
tenants/{tenant_id}/exports/{export_id}/...
runs/{run_id}/...                # salida del robot (bucket -sri aparte)
backups/{env}/{yyyy-mm-dd}.dump.gz
```

- **SSE** habilitado (SSE-S3 por defecto; SSE-KMS si el cliente lo exige); URLs firmadas con expiración corta (5–15 min).
- **Versionado** activado y *Block Public Access*; política que niega tráfico sin TLS.
- **Lifecycle:** XML → `STANDARD_IA` a los 90 días y `GLACIER_IR` a los 12 meses. Los exports temporales se limpian con un comando programado a los 7 días (no con una regla de prefijo, para no borrar XML por error). La retención fiscal (≥ 7 años) se revisa con el contador antes de configurar expiración automática.
- **IAM mínimo:** un usuario/rol por entorno, limitado a su bucket (`tenants/*` y `backups/*`); el robot solo escribe en `runs/*` del bucket `-sri`.
- Nada se sirve públicamente: descargas vía controlador autorizado con URL firmada.
- Verificar `sha256` al importar; un archivo alterado se rechaza y se audita.
- **Guarda de ambiente:** la aplicación valida que `AWS_BUCKET` corresponda al entorno (`APP_ENV`) al arrancar. Mapeo permitido: `local → autotributo-local-*`, `testing → autotributo-test-*`, `preview → autotributo-test-*`, `staging → autotributo-staging-*`, `production → autotributo-prod-*`. Un bucket de producción con `APP_ENV=local` es un error fatal.

## 9. WebSockets

- Adjuntar la aplicación WebSocket al entorno; Cloud inyecta `REVERB_*`.
- Canales privados autorizados con policies (pertenencia al tenant y a la empresa).
- Los eventos son *best-effort*: la UI reconcilia por polling cada 5 s mientras un run está activo.
- En local se usa el contenedor `reverb`; en Cloud no se administra ningún proceso propio.
- Cuidado con `scale-to-zero`: el cluster de WebSockets es independiente y permanece disponible.

## 10. Flujo de release

```
feature branch → PR → CI (lint, tests, e2e, seguridad) → preview env
      → merge a main → deploy automático a staging → smoke tests → UAT
      → tag vX.Y.Z → promoción manual a producción → verificación post-deploy
```

- **Branch protection:** CI verde + 1 aprobación + rama actualizada.
- **Versionado:** SemVer; `CHANGELOG.md` generado desde commits convencionales.
- **Smoke tests post-deploy:** `/up`, login, listado de facturas, estado de Reverb, una sincronización de prueba con empresa sandbox (solo staging).

## 11. Rollback

1. **Código:** promover el deployment anterior desde Cloud (segundos).
2. **Base de datos:** el esquema debe ser compatible con la versión anterior durante un release. Si no lo es, se aplica un *forward fix*, no un rollback de migración.
3. **Feature flag:** toda funcionalidad riesgosa se despliega apagada (`config('features.x')` o Pennant) y se enciende tras validar.
4. **Colas:** los jobs de la versión anterior deben seguir siendo procesables; los nuevos campos son opcionales durante un release.

## 12. AWS (lado robot)

| Elemento | Configuración |
|---|---|
| Función Lambda | Timeout 15 min, memoria 1–2 GB, `/tmp` ampliado solo si es imprescindible (mejor streaming a S3) |
| Invocación | Asíncrona (`Event`); destino on-success/on-failure a S3 |
| Credenciales | Clave privada de sellado en Secrets Manager; rotación anual |
| Permisos | Escritura solo en `sri-robot/{env}/*`; lectura del secreto; logs en CloudWatch |
| Concurrencia | Límite reservado para no saturar el portal del SRI ni el presupuesto |
| Alarmas | Errores > 5 % en 5 min, duración p95 > 10 min, throttles |
| Retención de logs | 30 días (sin datos sensibles) |

El robot **no** tiene acceso a la base de datos ni a la API interna; solo escribe en S3 y llama al webhook firmado.

## 13. Costes: palancas

- App cluster en scale-to-zero solo para staging/preview; producción siempre con mínimo 1.
- Colas Flex para cargas intermitentes; Pro solo para reportes que exceden 90 s.
- Caché: empezar con `database` y migrar a Valkey cuando la latencia lo exija (Valkey ya está previsto).
- LLM: modelo pequeño, caché por descripción normalizada, límite por tenant, evaluación de coste por release.
- Object Storage: lifecycle a clases frías y purga de exports.
- Logs: muestreo y retención corta en el proveedor; Sentry con filtros.
- Definir **límite de gasto mensual** en Laravel Cloud y alarmas al 50/80/100 %.

## 14. Runbooks

### R1 — Fallos masivos de sincronización SRI
1. Revisar dashboard de Queues y `sri_sync_runs` por estado/error.
2. Verificar CloudWatch de la Lambda (errores, throttles, duración).
3. ¿El portal del SRI cambió? → activar modo `manual_upload`, comunicar a clientes, abrir incidente con el responsable del robot.
4. Reintentar runs `failed` con `php artisan sri:retry-run {id}` (idempotente).

### R2 — Lambda caída o timeout
1. Invocar manualmente con un payload de prueba.
2. Revisar límites de concurrencia y cuotas de AWS.
3. Si persiste > 30 min, cambiar `SRI_ROBOT_DRIVER` a `manual` y notificar.

### R3 — Cola atascada
1. Revisar profundidad y edad del mensaje más antiguo.
2. Pausar la cola desde el dashboard si hay un bucle tóxico.
3. Identificar el job con `run_id`/`invoice_id`, corregir y reintentar.
4. Si es un veneno recurrente, mover a `failed` y documentar.

### R4 — Sospecha de fuga de credenciales
1. Revocar credenciales SRI de las empresas afectadas y marcarlas `invalid`.
2. Rotar `SRI_CREDENTIALS_KEY`, `SRI_ROBOT_CALLBACK_SECRET`, claves AWS y API keys.
3. Revisar `sri_credential_accesses` y `audit_logs`; notificar según LOPDP.
4. Postmortem sin culpables en 72 h.

### R5 — Restauración de base de datos
1. Crear una base nueva desde PITR en el minuto previo al incidente.
2. Validar integridad (conteos, checksums de períodos, login).
3. Cambiar `DB_*` al nuevo recurso y redeploy.
4. Comunicar downtime y RPO real.

### R6 — Error en una declaración aprobada
1. No editar: crear nueva versión con `supersedes_id`.
2. Identificar causa (regla, tarifa, dato de entrada) y corregir catálogo/regla.
3. Recalcular, comparar con el cálculo del contador, aprobar nueva versión.
4. Añadir test de regresión con el caso.

### R7 — Offboarding del estudio
1. Exportar datos (JSON/CSV + XML) y entregar por canal seguro.
2. Revocar credenciales SRI y borrar secretos.
3. Borrar objetos S3 y datos personales según política; conservar lo exigido por ley con acceso restringido.
4. Registrar en auditoría y emitir constancia.

## 15. Checklist de go-live

- [ ] Staging con datos representativos y UAT firmada por el contador.
- [ ] Backups activos y **restauración probada** (RTO medido).
- [ ] Alertas probadas (error 500, cola, Lambda, presupuesto IA).
- [ ] Límite de gasto configurado en Cloud y en el proveedor de IA.
- [ ] Dominio y TLS verificados (modo single; los subdominios de tenant quedan reservados para multi).
- [ ] Bucket S3 del entorno correcto, IAM mínimo, SSE, versionado y lifecycle verificados.
- [ ] Webhooks con secreto de producción y tolerancia de reloj validada.
- [ ] Rotación de secretos documentada y copia de `SRI_CREDENTIALS_KEY` en el gestor de la organización.
- [ ] Runbooks revisados por una segunda persona.
- [ ] Plan de soporte y canal para clientes beta.
- [ ] Aviso de privacidad, términos y consentimiento de credenciales publicados.
