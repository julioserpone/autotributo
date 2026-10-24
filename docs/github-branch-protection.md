# Protección de `main` en GitHub

## Política

`main` es la rama desplegable y no acepta cambios directos.

| Regla | Valor |
|---|---|
| Push directo | Bloqueado (`pull_request` obligatorio) |
| Pull request | Obligatorio |
| Aprobaciones obligatorias | `0` (maintainer único) |
| Merge permitido | Solo squash |
| Force push | Bloqueado |
| Borrado de `main` | Bloqueado |
| Historial lineal | Obligatorio |
| Rama actualizada antes del merge | Sí |
| Conversaciones resueltas | Sí |
| Bypass | Nadie (tampoco administradores) |
| Merges mientras falten checks | Bloqueado |
| Checks obligatorios | Backend, Frontend, Security, Workflow lint, Conventional PR title |

La fuente de verdad es el ruleset versionado en
[`.github/rulesets/main-protection.json`](../.github/rulesets/main-protection.json),
aplicado al repositorio con `scripts/apply-branch-protection.sh`.

## Por qué el botón de merge aparecía habilitado

El ruleset combina tres reglas y cada una cubre un caso distinto:

1. `pull_request`: exige PR para llegar a `main`.
2. `required_status_checks`: exige que cada check de la lista **exista y haya
   pasado** antes de habilitar el merge. Al ser un ruleset (no la protección
   clásica), aplica igual a administradores: no hay bypass, así que un PR con CI
   en rojo o pendiente no se puede mergear ni con force ni con "Merge anyway".
3. `non_fast_forward`, `deletion` y `required_linear_history`: cierran force
   push, borrado y merges que rompan la linealidad.

Dos detalles explican el comportamiento anterior:

- Si un check obligatorio **nunca se reporta** (por ejemplo, el workflow no se
  dispara para ese PR), GitHub lo considera pendiente de forma indefinida. Los
  workflows actuales publican siempre su check porque no tienen `paths` ni
  `if:` a nivel de job, y ningún job requerido depende de infraestructura
  pesada (Playwright se ejecuta en local).
- Con `strict_required_status_checks_policy: true` el PR debe estar al día con
  `main`; si `main` avanzó, hay que actualizar la rama y esperar a que CI vuelva
  a correr antes de mergear.

Verificación de un PR:

```bash
gh pr view <n> --json mergeStateStatus,statusCheckRollup
# BLOCKED mientras falte un check; CLEAN cuando todo está verde
```

## Checks obligatorios

Los contexts deben coincidir **exactamente** con el `name` de cada job en
`.github/workflows/`:

| Workflow | Job (`name`) |
|---|---|
| `ci.yml` | `Backend (Pint, PHPStan, Pest)` |
| `ci.yml` | `Frontend (types, lint, Vitest, build)` |
| `ci.yml` | `Security (secrets and dependencies)` |
| `ci.yml` | `Workflow lint (actionlint)` |
| `pr-title.yml` | `Conventional PR title` |

Si renombras un job, renómbralo también en el ruleset y vuelve a aplicar el
script; si no, el check viejo queda pendiente para siempre y `main` deja de
recibir merges.

Para renovar los contexts sin bloquear PRs abiertos, aplica el ruleset en dos
tiempos: primero el workflow que publica el job nuevo, después el ruleset que lo
exige y retira el viejo. Un ruleset se aplica al instante, pero los checks de un
PR se evalúan con el workflow de la **rama base**: si el base todavía declara el
job eliminado, GitHub lo ejecuta y lo reporta.

## E2E fuera de CI

Playwright levanta todo el stack de Docker Compose (build de la imagen, Postgres
y Valkey, migraciones, Vite), tarda varios minutos y consume muchos minutos de
runner. Por eso **no** forma parte del pipeline de GitHub ni es un check
obligatorio:

- Se ejecuta en local con `make up && make migrate && make seed` y `make e2e`.
- El checklist de `.github/PULL_REQUEST_TEMPLATE.md` exige ejecutarlo antes de
  mergear cambios de UI.
- No se agrega de vuelta a CI salvo decisión explícita; si algún día se hace,
  debe publicarse primero como job y después añadirse al ruleset.

## Aplicar la configuración

El repositorio es público y la cuenta es administradora, así que los rulesets
están disponibles en GitHub Free:

```bash
gh auth login
./scripts/apply-branch-protection.sh julioserpone/autotributo
```

El script:

1. Configura merge squash-only, desactiva merge commit/rebase y activa el
   borrado automático de la rama.
2. Crea o actualiza el ruleset `main-protection`.
3. Falla con un mensaje claro si GitHub rechaza el ruleset.

Verificación:

```bash
gh api repos/julioserpone/autotributo/rulesets \
  --jq '.[] | select(.name == "main-protection")'

# Debe fallar con "protected branch hook declined" / "Cannot update this protected ref":
git commit --allow-empty -m "test: direct push" && git push origin HEAD:main
```

## Aprobaciones

La política usa `required_approving_review_count: 0` para que el maintainer
único pueda mergear sin aprobaciones de otros usuarios. El PR, los checks y la
rama actualizada siguen siendo obligatorios.

Si se incorpora un equipo o se quiere revisión obligatoria, cambia el valor en
`.github/rulesets/main-protection.json` y vuelve a aplicar el script:

| Escenario | `required_approving_review_count` |
|---|---|
| Maintainer único | `0` |
| Equipo, cambios generales | `1` |
| Cálculos fiscales, tenancy o seguridad | `2` |

Para exigir revisión de `CODEOWNERS` activa `require_code_owner_review: true`
(requiere equipo con permisos de escritura y desmarca la posibilidad de
auto-aprobación del autor).

## Notas

- `.github/CODEOWNERS` solicita revisión automáticamente al maintainer, pero no
  la exige.
- Los hooks locales (`.githooks/pre-push`) evitan pushes directos desde una
  máquina con `make hooks`, pero **no** son la protección del servidor: se
  pueden omitir con `--no-verify`. El ruleset es la garantía real.
