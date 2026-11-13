<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Actions;

use App\Domain\Fiscal\Models\FormLine;
use App\Domain\Fiscal\Models\PersonalExpenseCap;
use App\Domain\Fiscal\Models\RetentionRate;
use App\Domain\Fiscal\Models\TaxRate;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Valida integridad de los catálogos fiscales: vigencias inválidas,
 * solapamientos, huecos dentro de una misma clave y aritmética de topes.
 *
 * Devuelve una lista de incidencias legibles; vacía significa catálogo sano.
 */
final readonly class ValidateFiscalCatalogs
{
    /**
     * @return list<string>
     */
    public function handle(): array
    {
        return [
            ...$this->validatePeriods(TaxRate::class, 'tax_rates', ['code', 'sri_percentage_code'], checkGaps: false),
            ...$this->validatePeriods(RetentionRate::class, 'retention_rates', ['tax_type', 'code']),
            ...$this->validatePeriods(FormLine::class, 'form_lines', ['form_code', 'line_code']),
            ...$this->validatePeriods(PersonalExpenseCap::class, 'personal_expense_caps', ['fiscal_year', 'category_code', 'dependents']),
            ...$this->validateCaps(),
        ];
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @param  list<string>  $groupBy
     * @return list<string>
     */
    private function validatePeriods(
        string $modelClass,
        string $table,
        array $groupBy,
        bool $checkGaps = true,
    ): array {
        $issues = [];

        /** @var array<string, list<TModel>> $groups */
        $groups = [];

        foreach ($modelClass::query()->orderBy('valid_from')->get() as $row) {
            $parts = [];

            foreach ($groupBy as $column) {
                $value = $row->getAttribute($column);
                $parts[] = $value instanceof BackedEnum ? (string) $value->value : (string) $value;
            }

            $groups[implode('|', $parts)][] = $row;
        }

        foreach ($groups as $key => $rows) {
            $previous = null;

            foreach ($rows as $row) {
                $from = $row->getAttribute('valid_from');
                $to = $row->getAttribute('valid_to');

                if ($from === null) {
                    $issues[] = "{$table} [{$key}]: falta valid_from.";
                    $previous = $row;

                    continue;
                }

                $fromDate = Carbon::parse((string) $from);

                if ($to !== null && Carbon::parse((string) $to)->lt($fromDate)) {
                    $issues[] = "{$table} [{$key}]: valid_to anterior a valid_from ({$fromDate->toDateString()}).";
                }

                if ($previous !== null) {
                    $previousTo = $previous->getAttribute('valid_to');
                    $currentStart = $fromDate->copy();

                    if ($previousTo === null) {
                        $issues[] = "{$table} [{$key}]: la versión anterior no tiene valid_to y se solapa con {$currentStart->toDateString()}.";
                    } else {
                        $previousEnd = Carbon::parse((string) $previousTo);

                        if ($previousEnd->gte($currentStart)) {
                            $issues[] = "{$table} [{$key}]: solapamiento entre {$previousEnd->toDateString()} y {$currentStart->toDateString()}.";
                        } elseif ($checkGaps && $previousEnd->copy()->addDay()->lt($currentStart)) {
                            $issues[] = "{$table} [{$key}]: hueco entre {$previousEnd->toDateString()} y {$currentStart->toDateString()}.";
                        }
                    }
                }

                $previous = $row;
            }
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    private function validateCaps(): array
    {
        $issues = [];

        foreach (PersonalExpenseCap::query()->get() as $cap) {
            if ($cap->baskets === null) {
                continue;
            }

            $expected = $cap->baskets * $cap->basic_food_basket_cents;

            if ($cap->cap_cents !== $expected) {
                $issues[] = sprintf(
                    'personal_expense_caps [%d/%s/%d]: cap_cents=%d no coincide con %d canastas x %d.',
                    $cap->fiscal_year,
                    $cap->category_code,
                    $cap->dependents,
                    $cap->cap_cents,
                    $cap->baskets,
                    $cap->basic_food_basket_cents,
                );
            }
        }

        return $issues;
    }
}
