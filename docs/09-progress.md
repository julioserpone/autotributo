# 09 — Estado de avance

> Tabla viva del proyecto. Se actualiza al cerrar cada tarea (una tarea = un commit/PR). La fuente de verdad del alcance sigue siendo `docs/02-implementation-plan.md`.

**Última actualización:** 2026-10-22

## Resumen por fase

| Fase | Estado | Avance | Último hito |
|---|---|---|---|
| F0 · Descubrimiento | Completado | 9/9 | Decisiones, ADRs y entorno local |
| F1 · Núcleo tenant-ready | En curso | 2/11 | F1-02 · Tenancy-ready y aislamiento por tenant |
| F2 · Ingesta SRI | Pendiente | 0/12 | — |
| F3 · Motor fiscal + IA | Pendiente | 0/9 | — |
| F4 · Frontend | Pendiente | 0/12 | — |
| F5 · Declaraciones | Pendiente | 0/12 | — |
| F6 · Hardening y beta | Pendiente | 0/8 | — |

## Fase 1 · Núcleo tenant-ready, identidad y empresas

| ID | Tarea | Estado | Evidencia |
|---|---|---|---|
| F1-01 | Esqueleto Laravel 13 + SPA | Completado | `042d1bf` · Pest, PHPStan 8, Pint, Vitest, E2E y 100% cobertura |
| F1-02 | Tenancy-ready | Completado | `bcf5896` · `stancl/tenancy` 3.10.1 sin DB por tenant · TenantContext, TenantScope, BelongsToTenant, TenantAware, caché/storage prefijados · 30 tests, 98.2% cobertura |
| F1-03 | Identidad | Pendiente | — |
| F1-04 | Autorización | Pendiente | — |
| F1-05 | Empresas | Pendiente | — |
| F1-06 | Credenciales SRI | Pendiente | — |
| F1-07 | Auditoría base | Pendiente | — |
| F1-08 | Reverb | Pendiente | — |
| F1-09 | Convenciones de API | Pendiente | — |
| F1-10 | Provisión inicial | Pendiente | — |
| F1-11 | Deploy a staging | Pendiente | — |

## Cómo actualizar

1. Cambiar el estado de la tarea a `Completado` y añadir commit + evidencia verificable.
2. Actualizar el avance de la fase en el resumen.
3. No marcar una tarea como completada si sus pruebas no están en verde o falta el criterio de salida de `docs/02-implementation-plan.md`.
