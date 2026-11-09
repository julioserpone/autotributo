# 02. Git Workflow & PR Rules

> La plantilla oficial de PR es `.github/pull_request_template.md`. Este documento resume y enlaza; no la duplica.

## 1. Branch Naming Conventions
Ramas en inglés, por tipo de cambio:
- `feat/<short-description>`: nueva funcionalidad.
- `fix/<short-description>`: corrección de bug.
- `refactor/<short-description>`: mejora arquitectónica sin cambio funcional.
- `docs/<short-description>`: documentación.
- `test/<short-description>`: tests.
- `chore/<short-description>`: mantenimiento/configuración.

## 2. Conventional Commit Standards
`<type>(<scope>): <description>` en inglés.

Tipos: `feat`, `fix`, `refactor`, `test`, `docs`, `chore`, `perf`, `revert`.

Ejemplos:
- `feat(companies): add RUC validation and company CRUD`
- `fix(sri): update authorization header token expiration`
- `docs(ai): align guides with Laravel 13 and single-DB tenancy`

## 3. Pull Requests
- Una tarea por PR, trunk-based, squash merge. Rama actualizada con `main` antes de mergear.
- Usar `.github/pull_request_template.md` (multitenancy, firma fiscal, PII/secrets, rollback, tests, docs).
- CI verde: Pint, PHPStan nivel 8, Pest. Adicionalmente `composer validate --strict --no-check-publish`, checks de frontend y `actionlint` cuando aplique.
- No `sleep()`, llamadas de red reales ni credenciales reales en tests.

## 4. Tests
- En Docker: `docker compose exec -T app ./vendor/bin/pest` (o `make check`).
- `vendor/bin/pest` local falla por host `db`; no usarlo como validación.
- Cobertura mínima 80% en el módulo tocado.

## 5. Documentación por tarea
- Actualizar `docs/02-implementation-plan.md`, `docs/09-progress.md` y `CHANGELOG.md` (sección Unreleased) en cada PR.
- Actualizar OpenAPI/tipos si cambia el contrato API.
- Textos de usuario en español; identificadores y archivos en inglés.
