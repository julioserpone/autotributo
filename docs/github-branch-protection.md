# Protección de `main` en GitHub

## Política deseada

`main` es la rama desplegable y no acepta cambios directos.

| Regla | Valor |
|---|---|
| Push directo | Bloqueado |
| Pull request | Obligatorio |
| Aprobaciones obligatorias | `0` (maintainer único) |
| Merge permitido | Solo squash |
| Force push | Bloqueado |
| Borrado de `main` | Bloqueado |
| Historial lineal | Obligatorio |
| Rama actualizada antes del merge | Sí |
| Conversaciones resueltas | Sí |
| Bypass | Nadie |
| Checks obligatorios | Backend, Frontend, Security, Workflow lint |

La fuente de verdad es el ruleset versionado en
[`.github/rulesets/main-protection.json`](../.github/rulesets/main-protection.json).

## Estado actual

La API de GitHub rechaza la creación de rulesets y la protección clásica de rama
para este repositorio:

```text
403 Upgrade to GitHub Pro or make this repository public to enable this feature.
```

El repositorio es **privado** y la cuenta usa **GitHub Free**. En ese plan,
GitHub no expone branch protection ni rulesets para repositorios privados.

### Opciones para habilitarla

1. **GitHub Pro** (recomendado si el repositorio debe seguir privado).
2. **Hacer público el repositorio** (solo con autorización explícita; revisar
   secretos, historial y licencia antes).
3. Mantener la política documentada y usar los hooks locales como mitigación
   parcial mientras el repositorio siga privado en Free.

Los hooks locales (`.githooks/pre-push`) bloquean pushes directos desde una
máquina con `make hooks`, pero **no** son una protección del servidor: un
colaborador puede omitirlos con `--no-verify` o pushear desde otra máquina.

## Aplicar la configuración

Con GitHub Pro o un repositorio público:

```bash
gh auth login
./scripts/apply-branch-protection.sh julioserpone/autotributo
```

El script:

1. Configura merge squash-only, desactiva merge commit/rebase y activa el
   borrado automático de la rama.
2. Crea o actualiza el ruleset `main-protection`.
3. Falla con un mensaje claro si el plan de GitHub no permite rulesets.

Verificación:

```bash
gh api repos/julioserpone/autotributo/rulesets \
  --jq '.[] | select(.name == "main-protection")'
```

## Checks obligatorios

Los contexts deben coincidir exactamente con los nombres de job de
`.github/workflows/ci.yml`:

- `Backend (Pint, PHPStan, Pest)`
- `Frontend (types, lint, Vitest, build)`
- `Security (secrets and dependencies)`
- `Workflow lint (actionlint)`

E2E (`E2E (Playwright)`) se ejecuta, pero no bloquea el merge para evitar que un
fallo de infraestructura del stack local detenga cambios triviales. Si se
vuelve estable, se agrega al ruleset.

## Aprobaciones

La política actual usa `required_approving_review_count: 0` para permitir que el
maintainer único haga merge sin aprobaciones de otros usuarios. El PR sigue
siendo obligatorio y los checks deben estar verdes.

Si se incorpora un equipo o se quiere revisión obligatoria, cambia el valor en
`.github/rulesets/main-protection.json` y vuelve a aplicar el script. Para
cálculos fiscales, tenancy o seguridad se recomienda al menos una aprobación.

## Nota sobre `CODEOWNERS`

`.github/CODEOWNERS` solicita revisión automáticamente al maintainer, pero no
la exige. Para exigir code owner review, activa
`require_code_owner_review: true` en el ruleset y asegúrate de que el equipo
tenga permisos de escritura.
