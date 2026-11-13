<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Data;

/**
 * Resultado de sincronizar los catálogos fiscales.
 *
 * Distinguir creadas, actualizadas y sin cambios permite que `app:provision`
 * sea idempotente y que el comando explique qué hizo en cada ejecución.
 */
final readonly class CatalogSyncResult
{
    /**
     * @param  array<string, array{created: int, updated: int, unchanged: int}>  $tables
     */
    public function __construct(public array $tables) {}

    public function created(): int
    {
        $total = 0;

        foreach ($this->tables as $counts) {
            $total += $counts['created'];
        }

        return $total;
    }

    public function updated(): int
    {
        $total = 0;

        foreach ($this->tables as $counts) {
            $total += $counts['updated'];
        }

        return $total;
    }

    public function unchanged(): int
    {
        $total = 0;

        foreach ($this->tables as $counts) {
            $total += $counts['unchanged'];
        }

        return $total;
    }

    public function total(): int
    {
        return $this->created() + $this->updated() + $this->unchanged();
    }

    public function hasChanges(): bool
    {
        return $this->created() > 0 || $this->updated() > 0;
    }
}
