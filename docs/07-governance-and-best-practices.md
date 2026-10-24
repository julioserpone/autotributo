# 07 — Gobernanza y buenas prácticas

## 1. Git

**Modelo:** trunk-based con ramas cortas (< 3 días) y despliegue continuo a staging.

```
main ──●──●──●──●──●──●──►  (protegida, siempre desplegable)
        \    /  \   /
         feat  fix  chore     (vida máxima 3 días)
```

| Regla | Detalle |
|---|---|
| Rama | `feat/F2-06-parser-xml`, `fix/104-credito-anterior`, `chore/upgrade-laravel`, `docs/adr-0001` |
| Commits | [Conventional Commits](https://www.conventionalcommits.org/): `feat(sri): add signed callback endpoint` |
| Merge | Squash merge; el mensaje del PR se convierte en el commit |
| PR | ≤ 400 líneas de diff (sin lockfiles); si es mayor, dividir |
| Revisión | PR obligatorio; 0 aprobaciones obligatorias para el maintainer único. Con equipo: 1 aprobación, 2 si toca cálculos fiscales, tenancy o seguridad |
| Protección | Ruleset `main-protection`: CI obligatorio, rama actualizada, squash-only, historial lineal, sin force-push, sin borrado y sin merge directo. Ver [`docs/github-branch-protection.md`](github-branch-protection.md) |
| Releases | Tag `vX.Y.Z` (SemVer) + `CHANGELOG.md` generado |
| Hotfix | Rama desde `main` con test de regresión; deploy inmediato y postmortem si hubo impacto |

**Prohibido:** commits directos a `main`, `git push --force` a ramas compartidas, commitear `.env`, `vendor/`, `node_modules/`, dumps o XML reales.

## 2. Idioma y convención de nombres

**Regla:** los identificadores y **nombres de archivo van en inglés**; la documentación, la UI y los mensajes al usuario van en **español**.

| Ámbito | Idioma | Ejemplos |
|---|---|---|
| Archivos y directorios | Inglés | `InvoiceController.php`, `Form104CalculatorTest.php`, `docs/03-data-model.md`, `tests/Fixtures/xml/credit_note/` |
| Clases, interfaces, traits, enums, métodos, variables | Inglés | `InvoiceImported`, `SyncSriRun`, `$accessKey`, `$confidence` |
| Tablas, columnas, índices, rutas, eventos, jobs | Inglés | `invoices`, `issue_date`, `sync-runs`, `SriSyncProgressUpdated` |
| Comentarios, documentación, ADRs | Español permitido | — |
| UI, emails, errores de usuario | Español (vía i18n) | "Comprobantes del período" |
| Términos oficiales ecuatorianos / acrónimos | Se conservan | `RUC`, `SRI`, `IVA`, `ATS`, `RIMPE`, `Form104` |

Reglas concretas:

- Prohibidos los nombres mixtos (`getFacturas`, `InvoiceFactura`). Si el término oficial no tiene traducción razonable, se usa el acrónimo (`ruc`, `sri`) o un nombre inglés: `access_key` para "clave de acceso", `line_code` para "casillero", `withholding` para "retención".
- Valores de enum en snake_case inglés (`credit_note`, `needs_review`, `in_review`), salvo nombres propios de régimen (`rimpe_emprendedor`, `rimpe_negocio_popular`).
- Archivos de documentación y assets en `kebab-case` (`03-data-model.md`, `privacy-policy.pdf`); código según la convención del framework (`PascalCase` clases, `snake_case` columnas).
- Ramas, commits y mensajes de log en inglés (`feat/sri-signed-callback`); mensajes de usuario en español.
- Los tests también: `it('rejects an expired invitation')`, nunca `it('rechaza invitación expirada')`.

## 3. Estándares de código (backend)

### 2.1 Reglas generales

- `declare(strict_types=1);` en todos los archivos PHP.
- PSR-12 aplicado por **Pint** (configuración en `pint.json`); CI falla con diferencias.
- **PHPStan/Larastan nivel 8**, sin `@phpstan-ignore` sin justificación escrita.
- Nombres en inglés en código; textos de UI y mensajes en español (i18n).
- Sin lógica de negocio en controladores, modelos ni middleware.
- Sin `env()` fuera de `config/`; sin `config()` dentro de bucles.
- Sin `DB::raw()` con interpolación; parámetros siempre bindeados.
- Sin `SELECT *` en consultas de listados; usar proyecciones.
- Sin `dd()`, `dump()`, `var_dump()`, `ray()` en el código versionado.

### 2.2 Capas

| Elemento | Responsabilidad | Prohibido |
|---|---|---|
| Controller | Orquestar, delegar en una Action, devolver Resource | Queries, cálculos, transacciones largas |
| FormRequest | Validación y autorización de entrada | Reglas de negocio complejas |
| Action | Un caso de uso, transaccional | Depender de `Request`/`Response` |
| Service | Coordinación de varias Actions/adaptadores | Ser un "cajón de sastre" |
| Model | Relaciones, scopes, casts, accessors | Lógica fiscal, llamadas HTTP |
| Policy | Autorización por recurso | Consultas pesadas |
| Resource | Serialización | Lógica de negocio |
| Job | Ejecutar una unidad idempotente | Transportar payloads grandes |
| Event/Listener | Efectos secundarios desacoplados | Fuente de verdad |

**Ejemplo de Action:**

```php
final readonly class CreateCompany
{
    public function __construct(private Clock $clock) {}

    public function handle(Tenant $tenant, CompanyData $data, User $actor): Company
    {
        return DB::transaction(function () use ($tenant, $data, $actor) {
            $company = $tenant->companies()->create([
                ...$data->toArray(),
                'created_by' => $actor->id,
            ]);

            $company->obligations()->createMany(
                ObligationCatalog::for($company->regime, $this->clock->year())
            );

            event(new CompanyCreated($company));

            return $company;
        });
    }
}
```

### 2.3 Tipos y datos

- Enums nativos para estados y tipos (`InvoiceStatus`, `DocumentType`, `DeclarationStatus`), con `tryFrom` y validación.
- DTOs `readonly` (`spatie/laravel-data`) en los límites: HTTP, XML, LLM, Lambda. Nunca arrays sueltos entre capas.
- Value Objects para `Ruc`, `ClaveAcceso`, `Period`, `Money` (centavos). Se validan al construirse.
- `Money` con `brick/money`; prohibido `float` para importes, incluso en tests.
- Fechas: `CarbonImmutable`; `now()` solo dentro de Actions vía `Clock` inyectable.
- JSON: validar con `json_validate()`/schema y fallar con excepción de dominio.

### 2.4 Errores

- Excepciones de dominio explícitas (`InvoiceTotalsMismatch`, `SyncAlreadyRunning`, `TenantMismatch`).
- Handler HTTP → `problem+json` (RFC 9457) con `type`, `title`, `status`, `detail`, `errors`.
- Nunca exponer stack traces ni mensajes internos en producción.
- Los jobs registran contexto (`run_id`, `invoice_id`) y fallan con mensaje accionable.

### 2.5 Base de datos

- Migraciones pequeñas y nombradas por intención; sin lógica de datos.
- Índices justificados por una consulta real; revisar `EXPLAIN (ANALYZE, BUFFERS)` antes de agregar.
- FKs explícitas con `cascadeOnDelete`/`restrictOnDelete` según el caso.
- Transacciones cortas; **nunca** una llamada HTTP dentro de una transacción.
- Prohibido `truncate`/borrados masivos sin `where` y sin respaldo.

## 4. Estándares de frontend

- TypeScript `strict: true`, sin `any` (usar `unknown` + zod en los bordes).
- Componentes `<script setup lang="ts">`, props tipadas, emits tipados.
- Estado de servidor con TanStack Query (no duplicar en Pinia); Pinia solo para estado global de UI.
- Acceso a API centralizado en `lib/api/`; nunca `fetch` suelto en componentes.
- Formularios con vee-validate + zod; mensajes en español.
- Tokens de diseño en CSS variables; prohibido hardcodear colores fuera del sistema.
- Accesibilidad: navegación por teclado, `aria-*`, foco visible, contraste AA, `prefers-reduced-motion`.
- i18n: ningún texto hardcodeado en componentes; fechas `dd/mm/yyyy`; moneda USD.
- Componentes < 250 líneas; si crece, extraer composables o subcomponentes.
- Prohibido `v-html` con contenido de usuario.

## 5. ADRs (Architecture Decision Records)

Formato: `docs/adr/NNNN-titulo.md` con estado (`propuesto`, `aceptado`, `reemplazado`), contexto, decisión, consecuencias y alternativas.

Se requiere ADR cuando el cambio:
- Afecta el aislamiento entre tenants, el modelo de datos o el esquema de colas.
- Introduce una dependencia de infraestructura o un proveedor externo.
- Cambia el contrato público de la API de forma incompatible.
- Modifica la estrategia de dinero, redondeo o catálogos fiscales.

Plantilla:

```markdown
# ADR-0006: Título

- Estado: propuesto | aceptado | reemplazado por ADR-xxxx
- Fecha: 2026-10-22
- Decisores: ...

## Contexto
## Decisión
## Consecuencias (positivas / negativas / riesgos)
## Alternativas consideradas
```

## 6. Dependencias

- Versiones fijadas en `composer.lock` / `package-lock.json` (siempre commiteados).
- Renovate/Dependabot semanal, agrupado por tipo; los parches de seguridad se aplican en < 72 h.
- Antes de agregar un paquete: mantenimiento activo, licencia compatible, tamaño, alternativa nativa, superficie de seguridad.
- `composer audit` y `npm audit` bloquean el merge con vulnerabilidades high/critical.
- Actualizaciones mayores de Laravel: rama dedicada, suite completa, despliegue a staging y una semana de observación.

## 7. Seguridad operativa

- 2FA obligatorio en GitHub, Laravel Cloud, AWS y el proveedor de IA.
- Acceso a producción por rol mínimo; sin credenciales compartidas.
- Secretos rotados trimestralmente y tras cualquier salida de personal.
- `gitleaks` en CI y hooks locales; si se filtra un secreto, se **rota**, no solo se borra del historial.
- Revisión de dependencias y de configuración de CORS/CSP en cada release.
- Los datos de producción no se copian a local; se anonimizan.
- Escaneo de vulnerabilidades de la app (ZAP o equivalente) antes del go-live y semestralmente.

## 8. Documentación

- `docs/` es la fuente de verdad; el código sigue a la documentación o la actualiza en el mismo PR.
- Cada módulo tiene un README breve con propósito, tablas y eventos que emite.
- OpenAPI se regenera y versiona; el frontend consume tipos generados.
- Los runbooks viven en `docs/06-laravel-cloud-deployment.md` y se prueban.
- `CHANGELOG.md` por release, con sección de cambios fiscales.

## 9. Reglas para agentes de IA

Este repositorio está diseñado para ser ejecutado incrementalmente por agentes. Obligatorio:

1. **Leer antes de escribir:** `docs/01`, `docs/02`, `docs/03` y el ADR relacionado con la tarea.
2. **Una tarea por PR**, con el ID (`F2-06`) en el título.
3. **Test primero:** escribir el test que falla, luego el código. No se acepta una tarea sin tests.
4. **No inventar reglas fiscales.** Tarifas, casilleros, códigos de retención y topes salen de catálogos y de `docs/fiscal-signoff.md`. Si falta un dato, **detenerse y preguntar**.
5. **No cambiar decisiones de arquitectura** (tenancy, colas, dinero, SPA) sin ADR aprobado.
6. **Respetar los patrones existentes**; no hacer refactors masivos "de paso".
7. **No tocar** `main`, secretos, configuración de producción ni migraciones ya desplegadas.
8. **Verificar:** `make check` y, si aplica, `make test-contract`. Reportar el resultado real, no asumido.
9. **Documentar:** actualizar `docs/`, OpenAPI y `CHANGELOG` cuando corresponda.
10. **Detenerse en los hitos de fase** (`M1`…`M6`) para revisión humana. No avanzar de fase sin aprobación.
11. **Declarar incertidumbre** en el PR: supuestos, decisiones pendientes y riesgos.
12. **No generar datos reales** ni credenciales en fixtures, seeds o tests.

## 10. Revisión de código

Checklist del revisor:
- [ ] El cambio hace lo que dice la historia, sin alcance extra.
- [ ] Tests que fallan sin el cambio y cubren casos borde.
- [ ] **Aislamiento de tenant** verificado en consultas nuevas.
- [ ] Sin secretos, PII ni credenciales en logs.
- [ ] Sin `N+1` evidentes; índices para consultas nuevas.
- [ ] Migración compatible y reversible.
- [ ] Manejo de errores y estados vacíos.
- [ ] Nombres claros; sin comentarios que expliquen lo obvio.
- [ ] Documentación/ADR actualizados.

La revisión comenta el código, no a la persona. Los desacuerdos de diseño se resuelven con un ADR, no con hilos infinitos.

## 11. Incidentes

- Severidad: **S1** caída total o fuga de datos; **S2** función crítica degradada; **S3** bug con workaround.
- S1/S2: canal dedicado, responsable de incidente, actualizaciones cada 30 min.
- Postmortem sin culpables en ≤ 72 h: línea de tiempo, causa raíz, impacto, acciones con dueño y fecha.
- Todo incidente S1/S2 genera al menos un test de regresión.

## 12. Accesibilidad e internacionalización

- WCAG 2.1 AA como criterio de aceptación en componentes nuevos.
- Pruebas con teclado y lector de pantalla en los flujos principales.
- Fechas, números y moneda formateados con `Intl`/`Carbon` según `es-EC`.
- Zona horaria de visualización: `America/Guayaquil`; persistencia UTC.
- Textos de error accionables, sin jerga técnica para el usuario final.

## 13. Evolución de la API

- `/api/v1` estable durante el MVP; cambios incompatibles → `/api/v2` con periodo de solapamiento.
- Campos nuevos son opcionales y retrocompatibles; nunca cambiar el tipo de un campo existente.
- Deprecaciones anunciadas en `CHANGELOG`, con cabecera `Deprecation` y fecha de retiro.
- El contrato OpenAPI se valida en CI; un cambio de tipo rompe el build del frontend.
