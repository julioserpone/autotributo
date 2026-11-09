# 01. Architecture & Coding Standards

> Resumen operativo. La fuente de verdad es `docs/01-architecture.md` y los ADR en `docs/adr/`.
> Si hay conflicto, prevalecen esos documentos.

## 1. General Guidelines
- **Framework**: Laravel 13.x (composer `^13.17`).
- **PHP**: 8.5, con `declare(strict_types=1);` en todo `.php`.
- **Language policy**:
  - Código, variables, funciones, esquema BD: inglés estricto.
  - Discusión interactiva y explicaciones: español.
  - Comentarios en código: español o inglés.
- **Money**: todos los montos en **centavos enteros** (`*_cents`, `bigint`) con cast a `integer`. Nunca `float` ni `* 0.15`. No se usa `brick/money`.

## 2. Directory Structure & Layer Responsibilities
```
app/
├── Domain/
│   └── <Module>/
│       ├── Actions/      # Lógica de negocio de un solo propósito (final readonly, handle())
│       ├── Models/        # Modelos Eloquent del módulo (fillable estricto)
│       ├── Data/          # DTOs tipados (no app/DTOs)
│       ├── Enums/         # Enums respaldados
│       ├── Policies/     # Autorización
│       └── Http/         # Controllers/Requests/Resources del módulo (cuando aplica)
├── Http/
│   ├── Controllers/      # Entrada HTTP (delgados)
│   ├── Requests/         # Validación y autorización HTTP
│   └── Resources/        # Transformadores de respuesta
└── Integrations/
    └── Sri/              # Integraciones externas (SRI, AWS, etc.)
```
- `app/Models` solo para modelos transversales (p. ej. `User`).
- No existen `app/Actions`, `app/DTOs` ni `app/Services` como raíz.

## 3. Strict Code Examples

### Form Request (con policy y scoping por tenant)
```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Company;

use App\Domain\Companies\Models\Company;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', Company::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ruc' => [
                'required',
                'string',
                'size:13',
                Rule::unique('companies', 'ruc')->where(
                    fn (Builder $query): Builder => $query->where(
                        'tenant_id',
                        app(TenantContext::class)->id(),
                    ),
                ),
            ],
            'legal_name' => ['required', 'string', 'max:300'],
            'total_cents' => ['required', 'integer', 'min:0'],
        ];
    }
}
```

### Action (un solo propósito, centavos)
```php
<?php

declare(strict_types=1);

namespace App\Domain\Companies\Actions;

use App\Domain\Companies\Models\Company;
use App\Models\User;

final readonly class CreateCompany
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes, User $actor): Company
    {
        $attributes['created_by'] = $actor->getKey();
        $attributes['status'] = Company::STATUS_ACTIVE;

        return Company::query()->create($attributes);
    }
}
```

### Controller delgado
```php
public function store(StoreCompanyRequest $request, CreateCompany $action): CompanyResource
{
    $company = $action->handle($request->validated(), $request->user());

    return new CompanyResource($company);
}
```

## 4. Multi-Tenancy Scoping (single DB, tenant-ready)
- Un solo tenant por despliegue; no activar multi-tenancy (ADR-0006).
- Todas las tablas de negocio conservan `tenant_id`, FK e índices compuestos que empiezan por él.
- Los modelos usan `BelongsToTenant` + `TenantScope` (no traits de conexión tenant).
- Migraciones únicas en `database/migrations`; **no** separar `landlord/` y `tenant/`.
- Contexto: `app(TenantContext::class)` y `TenantContext::run()`. Los jobs implementan `TenantAware` e inicializan el contexto antes de tocar datos.
- Todo `Rule::unique`/`Rule::exists` debe filtrar por `tenant_id`.
- Cada endpoint nuevo requiere prueba de aislamiento (tenant A no lee tenant B).

## 5. Frontend
- Vue 3 (Composition API) + TypeScript + Tailwind. Sin React.
- Textos visibles al usuario en español; identificadores y archivos en inglés.
