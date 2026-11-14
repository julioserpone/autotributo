<?php

declare(strict_types=1);

namespace App\Domain\Classification\Services;

use App\Domain\Classification\Data\RuleConflictData;
use App\Domain\Classification\Enums\MatchType;
use App\Domain\Classification\Models\ClassificationRule;

/**
 * Detecta reglas que competirían por el mismo ítem con la misma prioridad.
 *
 * La detección es deliberadamente conservadora: no intenta resolver si dos
 * expresiones regulares se solapan (`regex` solo compara patrones idénticos),
 * porque eso es indecidible en general. Para los patrones literales avisa
 * cuando uno contiene al otro, que es el caso real de reglas duplicadas.
 */
final readonly class ClassificationRuleConflictDetector
{
    public function __construct(private ClassificationRuleMatcher $matcher) {}

    /**
     * @param  int|null  $ignoreId  Regla que se está editando y no debe chocar consigo misma
     * @return list<RuleConflictData>
     */
    public function detect(
        MatchType $matchType,
        string $pattern,
        int $priority,
        ?int $ignoreId = null,
    ): array {
        $candidates = ClassificationRule::query()
            ->where('is_active', true)
            ->where('priority', $priority)
            ->where('match_type', $matchType->value)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->get();

        $conflicts = [];

        foreach ($candidates as $candidate) {
            if (! $candidate instanceof ClassificationRule) {
                continue;
            }

            if ($this->collides($matchType, $pattern, (string) $candidate->pattern)) {
                $conflicts[] = new RuleConflictData(
                    id: $candidate->id,
                    name: $candidate->name,
                    matchType: $candidate->match_type->value,
                    pattern: $candidate->pattern,
                    priority: $candidate->priority,
                    reason: 'Mismo tipo de coincidencia y prioridad; los patrones se solapan.',
                );
            }
        }

        return $conflicts;
    }

    private function collides(MatchType $matchType, string $left, string $right): bool
    {
        $left = trim($left);
        $right = trim($right);

        if ($left === '' || $right === '') {
            return false;
        }

        if ($matchType->isRegex()) {
            return $left === $right;
        }

        if ($matchType === MatchType::ProviderRuc) {
            return $left === $right;
        }

        if ($matchType === MatchType::ItemCode) {
            return mb_strtoupper($left) === mb_strtoupper($right);
        }

        $left = $this->matcher->normalize($left);
        $right = $this->matcher->normalize($right);

        if ($left === $right) {
            return true;
        }

        // Solo `contains` y `starts_with` pueden solaparse por contención.
        if (! in_array($matchType, [MatchType::Contains, MatchType::StartsWith], true)) {
            return false;
        }

        return str_contains($left, $right) || str_contains($right, $left);
    }
}
