<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Fiscal\Models\FormLine;
use App\Domain\Fiscal\Models\PersonalExpenseCap;
use App\Domain\Fiscal\Models\RetentionRate;
use App\Domain\Fiscal\Models\TaxRate;
use App\Http\Controllers\Controller;
use App\Http\Resources\FiscalCatalogEntryResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Consulta de solo lectura de los catálogos fiscales versionados (F3-09).
 *
 * Los catálogos son globales (no llevan `tenant_id`): solo se leen, y cualquier
 * usuario autenticado con perfil activo puede consultarlos. La escritura sigue
 * siendo de despliegue (`catalogs:import` / `FiscalCatalogSeeder`), nunca por
 * API, porque una tarifa mal cargada cambia impuestos declarados.
 */
final class FiscalCatalogController extends Controller
{
    /**
     * @var array<string, class-string<Model>>
     */
    private const CATALOGS = [
        'tax-rates' => TaxRate::class,
        'retention-rates' => RetentionRate::class,
        'form-lines' => FormLine::class,
        'personal-expense-caps' => PersonalExpenseCap::class,
    ];

    public function taxRates(Request $request): JsonResponse
    {
        return $this->index($request, 'tax-rates');
    }

    public function retentionRates(Request $request): JsonResponse
    {
        return $this->index($request, 'retention-rates');
    }

    public function formLines(Request $request): JsonResponse
    {
        return $this->index($request, 'form-lines');
    }

    public function personalExpenseCaps(Request $request): JsonResponse
    {
        return $this->index($request, 'personal-expense-caps');
    }

    private function index(Request $request, string $catalog): JsonResponse
    {
        Gate::authorize('viewAny', $this->policyModel($catalog));

        $modelClass = self::CATALOGS[$catalog];

        /** @var Builder<Model> $query */
        $query = $modelClass::query();

        $request->validate([
            'valid_on' => ['nullable', 'date_format:Y-m-d'],
            'historical' => ['nullable', 'boolean'],
        ]);

        $validOn = $request->query('valid_on');

        // `validOn` y `currentlyValid` vienen del trait `VersionedCatalog`, que
        // PHPStan no puede resolver sobre `Builder<Model>`.
        if (is_string($validOn) && $validOn !== '') {
            $query = $this->applyVersionScope($query, 'validOn', Carbon::parse($validOn)->toDateString());
        } elseif (! $request->boolean('historical')) {
            $query = $this->applyVersionScope($query, 'currentlyValid');
        }

        $this->filter($query, $request, $catalog);

        /** @var list<Model> $entries */
        $entries = $query->orderBy('valid_from')->get()->all();

        return FiscalCatalogEntryResource::collection($entries)
            ->additional([
                'meta' => [
                    'catalog' => $catalog,
                    'count' => count($entries),
                    'valid_on' => is_string($validOn) ? $validOn : null,
                    'historical' => $request->boolean('historical'),
                    'as_of' => now()->toIso8601String(),
                ],
            ])
            ->response();
    }

    /**
     * Aplica un scope de vigencia del trait `VersionedCatalog`.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function applyVersionScope(Builder $query, string $scope, ?string $date = null): Builder
    {
        // `scopeValidOn` y `scopeCurrentlyValid` los publica el trait
        // `VersionedCatalog` a través de `__call`: PHPStan no los ve en la firma
        // del builder genérico, así que la llamada se hace vía `__call` directo.
        /** @var Builder<Model> $builder */
        $builder = $scope === 'validOn'
            ? $query->__call('validOn', [(string) $date])
            : $query->__call('currentlyValid', []);

        return $builder;
    }

    /**
     * Modelo sobre el que se autoriza la consulta del catálogo.
     *
     * @return class-string<Model>
     */
    private function policyModel(string $catalog): string
    {
        return self::CATALOGS[$catalog];
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function filter(Builder $query, Request $request, string $catalog): void
    {
        $code = trim((string) $request->query('code', ''));

        if ($code !== '') {
            $query->where($catalog === 'form-lines' ? 'line_code' : 'code', $code);
        }

        $search = trim((string) $request->query('q', ''));

        if ($search !== '') {
            $needle = '%'.mb_strtolower($search).'%';

            $query->where(function (Builder $builder) use ($needle, $catalog): void {
                $columns = match ($catalog) {
                    'form-lines' => ['label'],
                    'personal-expense-caps' => ['category_code'],
                    default => ['label'],
                };

                foreach ($columns as $column) {
                    $builder->orWhereRaw("LOWER({$column}) LIKE ?", [$needle]);
                }
            });
        }

        if ($catalog === 'retention-rates') {
            $taxType = trim((string) $request->query('tax_type', ''));

            if ($taxType !== '') {
                $query->where('tax_type', $taxType);
            }
        }

        if ($catalog === 'form-lines') {
            $formCode = trim((string) $request->query('form_code', ''));

            if ($formCode !== '') {
                $query->where('form_code', $formCode);
            }
        }

        if ($catalog === 'personal-expense-caps') {
            $year = $request->query('fiscal_year');

            if (is_string($year) && $year !== '') {
                $query->where('fiscal_year', (int) $year);
            }
        }
    }
}
