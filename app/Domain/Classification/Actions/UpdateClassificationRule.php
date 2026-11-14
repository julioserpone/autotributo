<?php

declare(strict_types=1);

namespace App\Domain\Classification\Actions;

use App\Domain\Classification\Enums\MatchType;
use App\Domain\Classification\Exceptions\ClassificationRuleConflict;
use App\Domain\Classification\Models\ClassificationRule;
use App\Domain\Classification\Services\ClassificationRuleConflictDetector;
use App\Domain\Classification\Services\ClassificationRuleMatcher;
use InvalidArgumentException;

/**
 * Actualiza una regla existente y vuelve a validar conflictos.
 */
final readonly class UpdateClassificationRule
{
    public function __construct(private ClassificationRuleConflictDetector $detector) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(ClassificationRule $rule, array $data): ClassificationRule
    {
        $matchType = array_key_exists('match_type', $data)
            ? MatchType::from((string) $data['match_type'])
            : $rule->match_type;

        $pattern = array_key_exists('pattern', $data)
            ? trim((string) $data['pattern'])
            : (string) $rule->pattern;

        $priority = array_key_exists('priority', $data)
            ? (int) $data['priority']
            : $rule->priority;

        if ($pattern === '') {
            throw new InvalidArgumentException('El patrón de la regla no puede estar vacío.');
        }

        if ($matchType->isRegex() && ! ClassificationRuleMatcher::isValidRegex($pattern)) {
            throw new InvalidArgumentException('La expresión regular de la regla no es válida.');
        }

        $conflicts = $this->detector->detect($matchType, $pattern, $priority, $rule->getKey());

        if ($conflicts !== [] && ! (bool) ($data['confirm_conflict'] ?? false)) {
            throw new ClassificationRuleConflict($conflicts);
        }

        $attributes = [];

        foreach (['name', 'priority', 'match_type', 'pattern', 'expense_category_id', 'is_active'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if (array_key_exists('name', $attributes)) {
            $attributes['name'] = trim((string) $attributes['name']);
        }

        $rule->fill($attributes);
        $rule->save();

        return $rule;
    }
}
