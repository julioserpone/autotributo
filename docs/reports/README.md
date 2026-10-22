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
