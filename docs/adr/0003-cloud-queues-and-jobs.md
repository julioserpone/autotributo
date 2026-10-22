# ADR-0003: Colas administradas de Laravel Cloud y diseño de jobs

- **Estado:** aceptado
- **Fecha:** 2026-10-22
- **Decisores:** backend, responsable técnico

## Contexto

Laravel Cloud ofrece colas administradas sobre SQS. Esto cambia supuestos clásicos de Laravel:

- Entrega **at-least-once** (un job puede ejecutarse dos veces).
- Payload máximo **~1 MiB**.
- Worker **Flex** con límite de ~90 s por job y hasta 2 GiB; **Pro** sin límite fijo.
- **No** soporta Horizon ni los comandos `queue:failed`, `queue:retry`, `queue:clear`.
- Delays de hasta 15 min en colas estándar; FIFO sin delays.
- El filesystem de cada worker es **efímero**.
- Los jobs de una cola administrada **sí** se ejecutan aunque el entorno esté en scale-to-zero.

En local usamos Valkey + `queue:work`, que no reproduce estas restricciones.

## Decisión

1. En Cloud, `QUEUE_CONNECTION=cloud`; en local, `redis`. La diferencia se encapsula en configuración, no en código de dominio.
2. **Todo job es idempotente.** La idempotencia se implementa en el dominio (upsert por clave natural, `idempotency_keys`, estados de run), no confiando en la cola.
3. Los jobs transportan **IDs y referencias a S3**, nunca XML, PDFs ni payloads grandes.
4. Un job = una unidad de trabajo acotada: un archivo o un lote de 25 documentos; nunca "procesar todo el run".
5. La orquestación vive en el dominio (`StartSriSync`, `ParseSriManifest`) y encadena jobs; ningún job depende del orden de llegada.
6. Reintentos con `$tries`, `$backoff` y `retryUntil()`; los fallos definitivos se registran en el dominio (`sri_sync_run_files`, `fiscal_findings`) además de la cola.
7. Colas separadas por perfil de recursos: `sri` (Flex), `ai` (Flex), `reports` (Pro), `default` (Flex).
8. Sin Horizon en ningún entorno; monitoreo en el dashboard de Queues/API de Cloud y en Sentry/Pulse.
9. No se usan delays largos: el Scheduler reprograma lo que necesite esperar más de 15 min.
10. Los tests verifican doble ejecución del mismo job y ausencia de duplicados.

## Consecuencias

**Positivas**
- Operación sin mantener workers ni Horizon.
- Escalado automático y coste por uso.
- Diseño robusto ante reintentos y fallos parciales.

**Negativas**
- No hay visibilidad de jobs fallidos vía Artisan; hay que usar el panel/API.
- La depuración requiere logs con `run_id`/`job_id`.
- El límite de 90 s obliga a trocear más de lo habitual.
- El comportamiento local (Redis) difiere del de producción (SQS); los tests de contrato e idempotencia son la red de seguridad.

## Alternativas consideradas

| Alternativa | Por qué no |
|---|---|
| Worker cluster con `queue:work` y Redis | Hay que dimensionar y operar; se paga siempre; se aleja de la recomendación de Cloud |
| Horizon | No soportado con colas administradas |
| Cola `database` | Sin soporte en Cloud para workers gestionados; menor rendimiento |
| Jobs con payload completo | Supera 1 MiB con facilidad (XML de facturas) y duplica datos |

## Reglas de revisión

- ¿El job puede ejecutarse dos veces sin efectos duplicados? (test obligatorio)
- ¿El payload es pequeño y contiene solo IDs/claves?
- ¿El peor caso cabe en 90 s? Si no, dividir o usar la cola `reports` (Pro).
- ¿Los errores quedan registrados en el dominio y son accionables?
