# 04 — Estrategia de pruebas

## 1. Principios

1. **Se prueba comportamiento, no implementación.** Los tests describen reglas de negocio ("una NC resta la base imponible del período"), no métodos privados.
2. **El test correcto para cada riesgo.** Lógica fiscal → unitario con tabla de casos; aislamiento → feature con dos tenants (aunque el MVP sea single-tenant); integraciones → contrato con dobles; flujo crítico → E2E.
3. **Determinismo total.** Sin red, sin `sleep()`, sin `now()` implícito, sin dependencia de orden entre tests.
4. **Un test que no puede fallar no sirve.** Se verifica que falle antes de implementar (red → green), y se evita el exceso de mocks que hace pasar tests rotos.
5. **Las pruebas son documentación ejecutable.** Un contador debe poder leer el caso y entender la regla.

## 2. Pirámide y herramientas

```
        E2E (Playwright)            5–8 flujos críticos
      Contrato (Pest + fakes)       adaptadores externos
    Feature (Pest + Postgres)       HTTP, jobs, policies, tenancy
  Unit (Pest, sin BD)              cálculos, DTOs, validadores
```

| Nivel | Herramienta | Alcance | Velocidad objetivo |
|---|---|---|---|
| Unit | Pest 4 | Dominio puro: `Money`, `Ruc`, `ClaveAcceso`, proporcionalidad, 104/103/102, parsers de campos | < 100 ms/test |
| Feature | Pest + Postgres real | Endpoints, jobs, policies, eventos, multitenancy, migraciones | < 1 s/test |
| Contrato | Pest + `Http::fake` + fixtures grabadas | AWS Lambda, S3, LLM, webhooks | < 300 ms/test |
| E2E | Playwright + MSW/backend real | Login→sync→clasificación→104→export | < 60 s/flujo |
| Frontend | Vitest + `@vue/test-utils` + MSW | Componentes, composables, formateo | < 50 ms/test |
| Carga | k6 | Listados, sync, generación de PDF | manual/nightly |
| Seguridad | Gitleaks, `composer audit`, ASVS checklist, ZAP (F6) | Secretos, dependencias, headers | CI |

**Base de datos:** siempre PostgreSQL 16 (local/CI), nunca SQLite. El proyecto usa `jsonb`, índices GIN, `CHECK` y agregaciones que SQLite no reproduce.

## 3. Estructura

```
tests/
├── Unit/
│   ├── Shared/RucTest.php
│   ├── Shared/ClaveAccesoTest.php
│   ├── Invoicing/XmlParserTest.php
│   ├── Fiscal/Form104CalculatorTest.php
│   ├── Fiscal/ProportionalityTest.php
│   └── Classification/RuleEngineTest.php
├── Feature/
│   ├── Auth/ (login, 2fa, invitaciones)
│   ├── Companies/
│   ├── Invoicing/
│   ├── Sri/ (sync, callback, reconciliación)
│   ├── Classification/
│   ├── Fiscal/ (declaraciones, versionado, permisos)
│   └── Isolation/ (acceso cruzado, obligatorio)
├── Contract/
│   ├── Sri/LambdaGatewayTest.php
│   ├── Classification/OpenAiClassifierTest.php
│   └── Webhooks/SignatureTest.php
├── Fixtures/
│   ├── xml/{invoice,credit_note,debit_note,purchase_settlement,retention}/
│   ├── sri-runs/
│   └── golden/{104,103,102}/
└── Arch/ArchitectureTest.php
```

## 4. Reglas por tipo de prueba

### 4.1 Unitarias de dominio

- Sin base de datos, sin contenedor, sin HTTP.
- Tablas de datos (`->with([...])`) para cubrir matriz de casos.
- Se comparan valores exactos en centavos y strings, no floats.

```php
it('valida el dígito verificador del RUC', function (string $ruc, bool $valid) {
    expect(Ruc::isValid($ruc))->toBe($valid);
})->with([
    ['0999999999001', true],
    ['0999999999002', false],
    ['1760012345001', true],
    ['0000000000000', false],
    ['099999999900', false],       // longitud incorrecta
    ['ABC9999999999', false],      // caracteres inválidos
]);

it('calcula el factor de proporcionalidad con 6 decimales', function () {
    $factor = Proportionality::factor(
        salesAtZero: Money::cents(100_00),
        salesAtGeneral: Money::cents(300_00),
    );

    expect($factor->toScale(6))->toBe('0.250000');
});
```

### 4.2 Feature (integración real)

- `RefreshDatabase` + factories con estados (`Company::factory()->rimpe()`, `Invoice::factory()->creditNote()`).
- Autenticación real con Sanctum (`actingAs`) y contexto de tenant.
- Se verifica la respuesta HTTP **y** el efecto en base de datos.
- `Queue::fake()` solo cuando el job no es el objeto de la prueba; si se prueba la cadena, usar `Queue::fake()` + `assertPushed` y probar el job por separado.

```php
it('no permite ver facturas de otro tenant', function () {
    [$tenantA, $tenantB] = Tenant::factory()->count(2)->create();
    $invoiceA = Invoice::factory()->for($tenantA)->create();

    tenancy()->initialize($tenantB);
    $userB = User::factory()->forTenant($tenantB)->create();

    $this->actingAs($userB)
        ->getJson("/api/v1/invoices/{$invoiceA->public_id}")
        ->assertNotFound();
});
```

**Suite de aislamiento obligatoria** (una por recurso expuesto): `invoices`, `invoice_items`, `companies`, `sync-runs`, `declarations`, `exports`, `classification_rules`, `audit_logs`.
Incluye también: listados con `filter[company_id]` ajeno, `POST` con `company_id` ajeno, descarga de PDF/XLSX ajeno, y canales de broadcasting ajenos.

> **Por qué se mantiene en el MVP single-tenant:** la app solo provisiona un estudio, pero el esquema ya es tenant-ready. Estos tests demuestran que el `TenantScope` funciona y que activar el modo multi (ADR-0006) no introducirá fugas. Además se prueba `TenantContext` en modo single: liga el tenant por defecto, falla sin contexto y no permite mezclar datos al cambiarlo.

### 4.3 Contrato

- El adaptador real nunca se ejecuta en tests: se prueba el **payload** que construye y la **interpretación** de la respuesta.
- Fixtures de respuestas reales grabadas y anonimizadas.
- Se valida el JSON Schema de entrada/salida del LLM y del manifiesto del robot.

```php
it('sella las credenciales y no las envía en claro', function () {
    $keypair = sodium_crypto_box_keypair();
    $publicKey = base64_encode(sodium_crypto_box_publickey($keypair));
    $lambda = Mockery::mock(LambdaClient::class);
    $lambda->shouldReceive('invoke')->once()->withArgs(function (array $args): bool {
        $payload = json_decode($args['Payload'], true);

        expect($payload)->not->toHaveKey('password')
            ->and($payload['sealed_credentials'])->toBeString()
            ->and($payload['sealed_credentials'])->not->toContain('clave-real');

        $plain = sodium_crypto_box_seal_open(
            base64_decode($payload['sealed_credentials'], true),
            $keypair,
        );

        expect($plain)->toContain('clave-real');

        return true;
    })->andReturn(new Result(['StatusCode' => 202]));

    $gateway = new LambdaSriRobotGateway($lambda, 'sri-txt-robot', $publicKey);
    $gateway->dispatch(new SriRobotRequest(
        ruc: '1758871873001',
        periods: ['2026-08'],
        credentials: new SriRobotCredentials('1758871873001', 'clave-real'),
    ));
});
```

### 4.4 E2E (Playwright)

Flujos mínimos:
1. Login + 2FA + cambio de empresa.
2. Crear empresa y validar RUC.
3. Iniciar sincronización y ver progreso hasta completar.
4. Revisar clasificación (aceptar/cambiar categoría) en bloque.
5. Abrir 104, verificar casilleros y drill-down.
6. Aprobar declaración y descargar PDF/XLSX.
7. Invitar usuario y verificar rol restringido.
8. Intento de acceso a recurso ajeno (404).

Reglas: `data-testid` estables, sin esperas arbitrarias (`expect(...).toBeVisible()`), datos sembrados por API/CLI, un usuario por test, limpieza por transacción o truncate.

Dónde se ejecutan: **en local** (`make up && make migrate && make seed` y `make e2e`), no en CI. Levantar el stack completo en un runner alojado tarda varios minutos y consume una cuota de minutos desproporcionada para el valor que aporta un smoke test; el checklist del PR exige correrlos antes de mergear cambios de UI. Si más adelante se automatizan en CI, se agregan como check obligatorio en el ruleset de `main`.

### 4.5 Frontend

- Componentes: props/eventos/estados (loading, empty, error), slots, accesibilidad (`getByRole`).
- Composables: fetch, paginación, filtros, debounce, manejo de errores 422/500.
- MSW para simular API; el contrato se valida contra los tipos generados desde OpenAPI.
- Snapshot solo para estructuras estables (formato de moneda/fecha), nunca para HTML completo.
- `axe-core` en los 4 componentes principales.

### 4.6 IA: evaluación offline

| Elemento | Regla |
|---|---|
| Dataset | ≥ 300 descripciones reales anonimizadas por categoría, versionado en `tests/Fixtures/ai/golden.jsonl` |
| Ejecución | `php artisan ai:evaluate --model=... --prompt=v1` (grupo `ai-eval`, nightly/manual) |
| Métricas | accuracy global, precisión/recall por categoría, matriz de confusión, coste medio, p95 de latencia |
| Umbral | accuracy ≥ 92 %; precisión "deducible" ≥ 95 %; si baja, el pipeline falla y bloquea el merge del prompt |
| Coste | se registra por ejecución en `docs/reports/ai-eval-YYYY-MM-DD.md` |
| Cambios de prompt | PR obligatorio con diff del reporte antes/después |

En tests normales, `FakeClassifier` es determinista y no consume red.

## 5. Casos obligatorios por módulo

| Módulo | Casos mínimos |
|---|---|
| `Ruc` / `ClaveAcceso` | Válidos (natural, sociedad, RIMPE), dígito verificador incorrecto, longitud, caracteres, módulo 11 con residuo 0/1 |
| Parser XML | Factura, NC, ND, liquidación, retención; ICE; propina; descuento global; tarifa 0 %; XML malformado; XXE; entidad externa; `<![CDATA[`; acentos; totales inconsistentes |
| Sync | Run duplicado, callback repetido, callback expirado, archivo corrupto, timeout de Lambda, S3 vacío, reanudación, cancelación, rate limit |
| Clasificación | Regla exacta/prefijo/regex, prioridad, sin match, caché hit/miss, LLM inválido, timeout, presupuesto agotado, revisión humana, cambio masivo |
| Riesgos | Duplicado exacto, mismo proveedor/fecha/monto distinto número, empresa fantasma, retención faltante, fuera de período, tope excedido |
| 104 | Solo ventas 15 %, ventas 0 % y 15 %, compras con crédito, NC, retenciones, crédito anterior, período sin movimiento, proporcionalidad, cambio de tarifa a mitad de mes |
| 103 | Retención IR e IVA, varios proveedores, códigos distintos, período sin retenciones |
| 102/101 | Topes por categoría, exceso, proyección anual, ingresos bajo umbral, RIMPE |
| Declaraciones | Borrador editable, aprobación, inmutabilidad, nueva versión, diferencias bloqueantes, permisos |
| Auth | Login correcto/incorrecto, lockout, 2FA, recovery code, invitación expirada/usada, reset, sesión revocada |
| Aislamiento | Todos los recursos y canales en tiempo real |

## 6. Fixtures y anonimización

- Los XML provienen de documentos reales **anonimizados** (RUC, nombres, direcciones y montos alterados de forma consistente) o generados sintéticamente.
- Cada fixture tiene un `README.md` con: tipo, escenario que cubre, resultado esperado y si proviene de caso real.
- Los montos se mantienen coherentes entre cabecera, ítems e impuestos; un fixture descuadrado debe estar marcado como tal.
- **Prohibido** commitear credenciales, XML con datos reales o capturas del portal del SRI.

## 7. Datos de prueba

- Factories para todas las entidades, con estados de negocio (`->authorized()`, `->creditNote()`, `->rimpe()`, `->forPeriod('2026-08')`).
- Builders de escenarios fiscales (`FiscalScenario::mixedSalesAndPurchases()`) para no repetir 40 líneas de setup.
- Seeds solo para demo local; los tests crean sus propios datos.
- Reloj congelado: `Carbon::setTestNow('2026-08-31 23:59:59')` cuando la lógica dependa de fechas.
- `Http::preventStrayRequests()` y `Storage::fake('local')` en el `TestCase` base. Los tests **nunca** usan AWS real: el disco local es el default y `Storage::fake()` aísla los archivos. Si se prueba el adaptador S3, se hace con un fake o con fixtures, no con credenciales.

## 8. Cobertura y calidad

| Gate | Umbral | Dónde |
|---|---|---|
| Cobertura total | ≥ 80 % | CI (`--min=80`) |
| `app/Domain/Fiscal` | ≥ 95 % | CI (suite separada) |
| Mutation score (Fiscal + Shared) | ≥ 70 % | Nightly (`infection`) |
| PHPStan/Larastan | Nivel 8, 0 errores | CI |
| Pint | 0 diferencias | CI |
| Dependencias | 0 vulnerabilidades high/critical | CI |
| Complejidad ciclomática | ≤ 10 por método | Revisión/PHPStan |
| Tests de arquitectura | 100 % | CI |

La cobertura es un piso, no un objetivo: no se agregan tests triviales para subir el número.

## 9. Tests de arquitectura

```php
arch('el dominio no depende de HTTP')
    ->expect('App\Domain')
    ->not->toUse(['Illuminate\Http', 'App\Http']);

arch('los controladores son finos')
    ->expect('App\Http\Controllers')
    ->toHaveMethods(['index', 'show', 'store', 'update', 'destroy', '__invoke', '__construct'])
    ->ignoring('App\Http\Controllers\Controller');

arch('no se usa el facade DB en el dominio')
    ->expect('App\Domain')
    ->not->toUse('Illuminate\Support\Facades\DB');

arch('todos los modelos de negocio usan el trait de tenant')
    ->expect('App\Domain\Invoicing\Models')
    ->toUse('App\Domain\Tenancy\Models\Concerns\BelongsToTenant');
```

## 10. Qué **no** se prueba (y por qué)

- Frameworks y paquetes de terceros (Laravel, Sanctum, Reverb).
- Getters/setters triviales y DTOs sin lógica (se cubren indirectamente).
- Detalles de implementación (nombres de métodos privados, queries exactas).
- HTML/CSS pixel-perfect (se prueba accesibilidad y comportamiento; el diseño se revisa visualmente).
- Respuestas reales del SRI en CI (inestables y no deterministas): se usan fixtures y un *smoke test* manual pre-release en staging.

## 11. Pipeline

```
pre-commit  → Pint (staged), gitleaks, tests unitarios rápidos
pre-push    → make test-unit + typecheck
local       → make e2e (Playwright, stack completo) antes de mergear cambios de UI
CI (PR)     → Pint --test, PHPStan, Pest (paralelo, cobertura), Vitest, build, actionlint, auditorías
nightly     → ai-eval, mutation testing, k6 smoke, restore de backup
```

Fallos de CI bloquean el merge: el ruleset de `main` no se puede saltar ni con permisos de administrador, y el botón de merge permanece bloqueado mientras falte cualquier check obligatorio (ver [`docs/github-branch-protection.md`](github-branch-protection.md)). Un test intermitente se arregla o se elimina en < 24 h; nunca se marca como `skip` sin issue y fecha.

## 12. Reporte de resultados

- Cobertura y resultados publicados como artefacto del PR.
- Playwright (local): `playwright-report/` en fallos.
- Evaluación IA: `docs/reports/ai-eval-YYYY-MM-DD.md` con tabla de métricas y coste.
- Cada release incluye un resumen: tests ejecutados, cobertura, incidencias conocidas y riesgos abiertos.
