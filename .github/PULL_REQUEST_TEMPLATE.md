# Pull Request

## Summary

<!-- One or two sentences. Link the task: F2-06 / US-xx. -->

Closes #

## Type of change

- [ ] `feat` new functionality
- [ ] `fix` bug fix
- [ ] `refactor` no behavior change
- [ ] `test` tests only
- [ ] `docs` documentation
- [ ] `chore` / `build` / `ci` infrastructure
- [ ] `perf` performance
- [ ] `revert` revert a previous change

## Scope and risk

- [ ] Does not touch fiscal calculations, catalogs, or form boxes
- [ ] Touches fiscal calculations → attached a verified case signed off by an accountant
- [ ] Touches migrations → follows expand/contract and is compatible with the previous deploy
- [ ] Touches personal data or credentials → reviewed against LOPDP and the secrets policy
- [ ] Introduces a breaking API change → documented and versioned
- [ ] Includes a rollback or mitigation plan

## Multitenancy

- [ ] Every new query filters by `tenant_id` (scope or explicit `where`)
- [ ] Added a test proving tenant A cannot read tenant B data
- [ ] Jobs and events initialize the tenant context before touching data

## Tests

- [ ] Unit tests for new logic
- [ ] Feature tests for new endpoints/flows
- [ ] Edge cases and failure paths covered
- [ ] `make check` is green locally
- [ ] `composer validate --strict --no-check-publish` is green
- [ ] `npm run typecheck && npm run lint && npm run test:unit -- --run` is green when frontend changed
- [ ] `actionlint` is green when workflows changed
- [ ] `make e2e` is green locally when the UI or the Docker stack changed (E2E is not run in CI)
- [ ] No `sleep()`, real network calls, or real credentials in tests

## Security and operations

- [ ] No secrets, tokens, keys, real XML, or PII in code, fixtures, logs, or screenshots
- [ ] New dependencies reviewed (maintenance, license, size, security)
- [ ] Structured logs include `tenant_id` and `run_id` when applicable
- [ ] Queue payloads < 1 MiB and jobs < 90 s in Laravel Cloud Flex
- [ ] Migration/index impact documented
- [ ] `.env.example` / `.env.testing.example` updated if configuration changed

## Documentation

- [ ] Updated `docs/` or ADR when a decision changed
- [ ] Updated `CHANGELOG.md` under `Unreleased`
- [ ] Updated OpenAPI/types when the API contract changed
- [ ] User-facing text is in Spanish; identifiers and file names are in English

## Evidence

<!-- Tests output, screenshots, query plans, or logs. -->

## Reviewer notes

<!-- Assumptions, trade-offs, open questions, or follow-up tasks. -->
