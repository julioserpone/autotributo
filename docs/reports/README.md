# Reports

Artefactos generados por los procesos de calidad. Se versionan para comparar evoluciones entre releases.

| Archivo | Origen | Frecuencia |
|---|---|---|
| `ai-eval-YYYY-MM-DD.md` | `php artisan ai:evaluate` | Semanal / antes de cambiar un prompt |
| `load-test-YYYY-MM-DD.md` | k6 | Antes de un release mayor |
| `restore-drill-YYYY-MM-DD.md` | Simulacro de restauración | Trimestral |

## Convenciones

- Un archivo por ejecución, con fecha ISO.
- Incluir: commit (`git sha`), entorno, modelo/prompt (si aplica), métricas y conclusión.
- No incluir datos personales, credenciales ni XML reales.
- Si una métrica baja del umbral, el reporte debe indicar la acción tomada.

## Pendientes (2026-11-21)

| Artefacto | Estado | Bloqueo |
|---|---|---|
| `ai-eval-YYYY-MM-DD.md` | **No existe** | F3-08 no puede ejecutarse sin el dataset dorado de `tests/Fixtures/ai/golden.jsonl` (≥ 300 descripciones reales anonimizadas) |
| Informe base de `F0-05` | **No existe** | F0 figura como completada 9/9 sin su entregable; hay que versionar el informe con el que se congeló el prompt `v1` |
| `load-test-YYYY-MM-DD.md` | Pendiente | F6-02 (k6) |
| `restore-drill-YYYY-MM-DD.md` | Pendiente | F6-04 (restauración cronometrada) |
