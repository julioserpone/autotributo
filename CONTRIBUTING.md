# Contributing

Gracias por contribuir a **Autotributo**. Este documento define el flujo de trabajo, los estándares mínimos y los gates que debe cumplir todo cambio.

## 1. Requisitos

- Docker y Docker Compose.
- PHP 8.5 y Composer 2 (o usa el contenedor `app`).
- Node.js 24 y npm.
- GitHub CLI (`gh`) para tareas de repositorio.
- Acceso al repositorio como colaborador; `main` no acepta pushes directos.

## 2. Puesta en marcha

```bash
cp .env.example .env
make up
make install
make hooks
```

`make hooks` configura `core.hooksPath=.githooks`, lo que habilita:

- `pre-push`: bloquea pushes directos a `main`.
- `commit-msg`: exige Conventional Commits.

Los hooks locales son una ayuda, no la protección definitiva. La política del servidor vive en [`docs/github-branch-protection.md`](docs/github-branch-protection.md).

## 3. Ramas

Crea una rama corta desde `main`:

```bash
git switch main
git pull --ff-only
git switch -c feat/F2-06-xml-parser
```

Convención:

| Prefijo | Uso | Ejemplo |
|---|---|---|
| `feat/` | Nueva funcionalidad | `feat/F2-06-xml-parser` |
| `fix/` | Corrección | `fix/104-credit-carryforward` |
| `refactor/` | Cambio interno sin comportamiento | `refactor/sri-run-state-machine` |
| `test/` | Solo pruebas | `test/reconcile-run-edge-cases` |
| `docs/` | Documentación | `docs/adr-0007-queue-contract` |
| `chore/` | Mantenimiento | `chore/upgrade-laravel` |
| `ci/` | CI/CD | `ci/add-actionlint` |

Una rama debe vivir menos de tres días. Si el cambio es grande, divídelo en PRs pequeños y encadenados.

## 4. Commits

Formato [Conventional Commits](https://www.conventionalcommits.org/):

```text
<type>(<scope>): <subject>

[optional body]

[optional footer]
```

Tipos permitidos: `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `build`, `ci`, `chore`, `revert`.

Ejemplos:

```text
feat(sri): reconcile active runs from the S3 manifest
fix(tenancy): scope company lookups by tenant
test(reconcile): cover malformed access-key files
docs(github): document branch protection
```

Reglas:

- Asunto en inglés, imperativo, minúscula inicial y sin punto final.
- Un commit = un cambio coherente.
- No incluyas secretos, credenciales, XML real, dumps ni datos personales.
- No reescribas historia de ramas compartidas.

## 5. Estándares de código

### Backend

- `declare(strict_types=1);` en todos los archivos PHP.
- Pint para estilo y PHPStan/Larastan nivel 8 para tipos.
- Sin lógica de negocio en controladores, modelos ni middleware.
- DTOs `readonly` y Value Objects en los límites.
- Sin `env()` fuera de `config/`; sin SQL interpolado.
- Sin `dd()`, `dump()`, `var_dump()` ni `ray()` versionados.
- Nombres de archivos, clases, variables y tablas en inglés; UI y documentación en español.

### Frontend

- TypeScript estricto, sin `any`; usa `unknown` y validación en los bordes.
- Componentes `<script setup lang="ts">` con props y emits tipados.
- Acceso a API centralizado; sin `fetch` suelto en componentes.
- Sin `v-html` con contenido de usuario.
- Textos de UI en español y accesibles (WCAG 2.1 AA).

### Base de datos

- Migraciones pequeñas, con intención clara y compatibles con el deploy anterior.
- Índices justificados por una consulta real.
- Nunca edites una migración ya desplegada; crea una nueva.
- Toda tabla de negocio lleva `tenant_id` y las consultas lo filtran.

## 6. Pruebas

La suite completa debe pasar antes de abrir el PR:

```bash
make check
```

Equivalente:

```bash
docker compose exec -T app ./vendor/bin/pint --test
docker compose exec -T app ./vendor/bin/phpstan analyse --memory-limit=1G --no-progress
docker compose exec -T app php artisan test
```

Base de datos de pruebas: la suite usa la base `autotributo_test` (definida en
`.env.testing.example`), separada de la de desarrollo. Si recreas los volúmenes
con `docker compose down -v`, vuelve a crearla una vez:

```bash
docker compose exec -T db psql -U autotributo -d postgres -c 'CREATE DATABASE autotributo_test;'
make migrate && make seed   # base de desarrollo
```

Si `php artisan test --parallel` responde con `database "autotributo_test" does not exist`, falta ese paso.

Reglas:

- Un cambio de comportamiento requiere un test que falle sin el cambio.
- Cubre caminos felices, errores, estados vacíos e idempotencia.
- Aísla tenants: un test debe probar que A no puede leer datos de B.
- Sin llamadas reales a SRI, AWS, LLM o correo; usa fakes o `Storage::fake()`.
- Sin `sleep()` para sincronizar; usa el reloj o colas falsas.
- Los tests no usan credenciales reales ni XML de contribuyentes reales.

Frontend:

```bash
npm run typecheck
npm run lint
npm run test:unit -- --run
npm run build
```

E2E (requiere el stack local y Playwright). **No corre en CI**: levanta todo el stack de Docker Compose, es lento y caro en runners alojados, así que se ejecuta en local antes de mergear cambios de UI:

```bash
make up && make migrate && make seed        # stack listo y migrado
npx playwright install chromium             # una sola vez
make e2e
```

El `smoke.spec.ts` actual valida que la SPA carga, que Vite sirve los assets y que `GET /api/v1/health` responde. Si tu UID/GID no es 1000, usa el override descrito en [`docs/05-local-docker-environment.md`](docs/05-local-docker-environment.md) para que Playwright pueda ejecutarse con el stack completo.

## 7. Seguridad

- Nunca commitees `.env`, claves, tokens, certificados, XML reales ni PII.
- No imprimas secretos en logs, errores, capturas o artefactos de CI.
- La contraseña SRI se cifra con `sodium_crypto_box_seal`; la clave privada vive solo en la Lambda.
- No uses AWS Secrets Manager para este flujo.
- Si sospechas una filtración, no abras un issue público: sigue [`SECURITY.md`](SECURITY.md) y rota el secreto.
- `gitleaks` corre en CI; una excepción solo se agrega en `.gitleaks.toml` con justificación y sin secretos reales.

## 8. Pull requests

1. Actualiza `main` y abre el PR contra `main`.
2. Usa el [template de PR](.github/PULL_REQUEST_TEMPLATE.md).
3. El título debe cumplir Conventional Commits; el workflow `PR title` lo valida y es un check obligatorio.
4. Mantén el diff pequeño (≤ 400 líneas sin lockfiles). Si es mayor, divide.
5. Verifica que CI esté verde: backend, frontend, actionlint y seguridad. El botón de merge permanece bloqueado mientras algún check obligatorio esté pendiente o en rojo, aunque tú seas administrador.
6. Resuelve conversaciones y deja el contexto de decisiones en el PR.
7. El merge es **squash**; el título del PR se convierte en el commit.

La política de aprobaciones se define en la protección de `main`. Por defecto el repositorio usa **0 aprobaciones obligatorias** para el maintainer único; si se incorpora equipo, se sube el mínimo en el ruleset. Consulta [`docs/github-branch-protection.md`](docs/github-branch-protection.md).

Si un check obligatorio no arranca (por ejemplo, el workflow cambió en el mismo PR y el merge queda esperándolo), **no** lo saltes: aleja el PR para que el workflow del archivo actual se dispare, o corrige el workflow en un PR aparte.

## 9. Documentación y decisiones

- Actualiza `docs/` en el mismo PR cuando cambie arquitectura, contrato, operación o seguridad.
- Un cambio de arquitectura, tenancy, colas, dinero o contrato público requiere un ADR en `docs/adr/`.
- Actualiza `CHANGELOG.md` en `Unreleased`.
- Los nombres de archivo y directorio van en inglés; el contenido puede ir en español.

## 10. Releases

- `main` siempre debe ser desplegable.
- El release usa SemVer (`vX.Y.Z`) y una entrada en `CHANGELOG.md`.
- Un hotfix sale de `main`, incluye un test de regresión y un postmortem si hubo impacto.

## 11. ¿Dudas?

- Lee [`SUPPORT.md`](SUPPORT.md).
- Para vulnerabilidades, usa [`SECURITY.md`](SECURITY.md).
- Para decisiones de producto o alcance, abre un issue con el template correspondiente.
