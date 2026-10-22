# Checklist de Pull Request

## Qué cambia
<!-- Una o dos frases. Enlaza la historia: US-xx / Fx-yy -->

## Tipo de cambio
- [ ] `feat` nueva funcionalidad
- [ ] `fix` corrección de bug
- [ ] `refactor` sin cambio de comportamiento
- [ ] `test` solo pruebas
- [ ] `docs` documentación
- [ ] `chore`/`build`/`ci` infraestructura
- [ ] `perf` rendimiento

## Riesgo fiscal / datos
- [ ] No toca cálculos fiscales, catálogos ni casilleros
- [ ] Toca cálculos fiscales → adjunto caso de prueba con cifras verificadas por un contador
- [ ] Toca migraciones → sigue el patrón expand/contract y es compatible con el deploy anterior
- [ ] Toca datos personales o credenciales → revisado contra LOPDP y política de secretos

## Multitenancy
- [ ] Toda consulta nueva filtra por `tenant_id` (scope o `where`)
- [ ] Incluí un test que verifica que el tenant A **no** puede leer datos del tenant B
- [ ] Jobs/eventos inicializan el contexto de tenant antes de tocar datos

## Pruebas
- [ ] Tests unitarios para la lógica nueva
- [ ] Tests de feature para endpoints/flujos nuevos
- [ ] Casos borde y errores cubiertos (no solo el camino feliz)
- [ ] `make check` en verde localmente
- [ ] Sin `sleep()` ni dependencias de red real en tests

## Operación
- [ ] Sin secretos, tokens ni claves en el código o logs
- [ ] Nombres de archivos, clases, variables, tablas y columnas en inglés; textos de usuario en español
- [ ] Logs estructurados con `tenant_id` y `run_id` cuando aplica
- [ ] Impacto en colas/tiempos documentado (payload < 1 MiB, jobs < 90 s en Flex)
- [ ] Actualicé docs/ADR si cambié una decisión de arquitectura

## Capturas / evidencia
<!-- UI: antes/después. Backend: salida de tests o query. -->
