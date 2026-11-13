<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Vigencia de un catálogo fiscal: `valid_from` inclusivo y `valid_to`
 * inclusivo; `valid_to` nulo significa que la fila sigue vigente.
 */
trait VersionedCatalog
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeValidOn(Builder $query, Carbon|string $date): Builder
    {
        $on = $date instanceof Carbon ? $date->toDateString() : $date;

        return $query
            ->where('valid_from', '<=', $on)
            ->where(function (Builder $nested) use ($on): void {
                $nested->whereNull('valid_to')->orWhere('valid_to', '>=', $on);
            });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCurrentlyValid(Builder $query): Builder
    {
        $today = Carbon::today()->toDateString();

        return $query
            ->where('valid_from', '<=', $today)
            ->where(function (Builder $nested) use ($today): void {
                $nested->whereNull('valid_to')->orWhere('valid_to', '>=', $today);
            });
    }

    public function isValidOn(Carbon|string $date): bool
    {
        $on = $date instanceof Carbon ? $date->copy()->startOfDay() : Carbon::parse($date)->startOfDay();
        $from = Carbon::parse((string) $this->getAttribute('valid_from'))->startOfDay();
        $to = $this->getAttribute('valid_to');

        if ($on->lt($from)) {
            return false;
        }

        return $to === null || $on->lte(Carbon::parse((string) $to)->startOfDay());
    }
}
