# AGENTS.md - Context & Instructions for AI Assistants (Autotributo)

## Language & persona
- **Chat, planning and explanations**: Spanish.
- **Code, schema, database, env keys and all identifiers**: English.
- **Comments**: Spanish or English, as needed.
- **Persona**: Senior Laravel Architect; single-tenant, tenant-ready SaaS.

## Stack (source of truth: `docs/01-architecture.md`)
- Laravel 13.x (composer `^13.17`), PHP 8.5, `declare(strict_types=1);` on every `.php`.
- PostgreSQL 16 (Neon). Money in **integer cents** (`*_cents`, `bigint`); never `float`.
- Frontend: Vue 3 (Composition API) + TypeScript + Tailwind. No React.
- Realtime/queues: Reverb, queues, AWS Lambda (SRI robot).
- Tests: Pest. CI gates: Pint, PHPStan level 8, Pest.

## Tenancy (ADR-0001, ADR-0006) - do NOT activate multi-tenancy
- Single database, single tenant per deploy. Every business table keeps `tenant_id`, FK and composite indexes starting with `tenant_id`.
- Isolation via `BelongsToTenant` + `TenantScope`. `DatabaseTenancyBootstrapper` is OFF.
- Tenant context: `TenantContext` (`app(TenantContext::class)`), `TenantContext::run()`. Jobs must be `TenantAware` and initialize context.
- No per-tenant databases/schemas/migrations. No `database/migrations/landlord|tenant`.

## Architecture & conventions (`docs/01-architecture.md`)
- Modules under `app/Domain/<Module>/{Actions,Models,Data,Enums,Policies,Http,...}`.
- HTTP layer under `app/Http/{Controllers,Requests,Resources}`; external integrations under `app/Integrations/Sri`.
- `app/Models` only for cross-cutting models (e.g. `User`).
- Actions: `final readonly` classes with `handle()`; one business operation per class.
- FormRequests: validation + authorization via policies (`$user->can(...)`, `Gate::authorize`); tenant-scoped `Rule::unique`/`Rule::exists` using `TenantContext::id()`.
- Controllers thin (invoke action + return resource). JsonResource for every response; never return raw models.
- DTOs live in `Data/` namespaces, not `DTOs/`.
- Money: integer cents only (`*_cents` columns with integer casts).

## Workflow (`docs/ai/02-git-and-pr-workflow.md`, `.github/pull_request_template.md`)
- One task per PR, trunk-based, squash merge. Branches: `feat/`, `fix/`, `refactor/`, `docs/`, `test/`, `chore/`.
- Conventional Commits in English.
- `make check` green before PR. Tests run in Docker: `docker compose exec -T app ./vendor/bin/pest` (local `vendor/bin/pest` fails, DB host `db`).
- Update `docs/02-implementation-plan.md`, `docs/09-progress.md` and `CHANGELOG.md` per task.

## Security
- No secrets, tokens, keys, real XML or PII in code, fixtures, logs or screenshots.
- SRI credentials encrypted AES-256-GCM with `SRI_CREDENTIALS_KEY`.
- Cross-tenant isolation test required for every new endpoint.
- Fiscal changes require accountant sign-off.

## UI / i18n
- User-facing text in Spanish; identifiers and file names in English.

## Sources of truth
- ADRs (`docs/adr/`) and `docs/01`-`docs/12` prevail over this file and `docs/ai/*`.
- If conflict, follow ADR + `docs/01` and open a docs PR to fix the drift.
- Full PR checklist: `.github/pull_request_template.md`.
