<?php

declare(strict_types=1);

namespace App\Domain\Classification\Exceptions;

use App\Domain\Classification\Data\RuleConflictData;
use RuntimeException;

/**
 * La regla choca con otra de la misma prioridad.
 *
 * No se guarda salvo que el cliente confirme el conflicto: dos reglas con el
 * mismo patrón y prioridad harían impredecible qué categoría gana.
 */
final class ClassificationRuleConflict extends RuntimeException
{
    /**
     * @param  list<RuleConflictData>  $conflicts
     */
    public function __construct(public readonly array $conflicts)
    {
        parent::__construct(
            'La regla entra en conflicto con otra regla activa de la misma prioridad.',
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function conflictsToArray(): array
    {
        return array_map(
            static fn (RuleConflictData $conflict): array => $conflict->toArray(),
            $this->conflicts,
        );
    }
}
