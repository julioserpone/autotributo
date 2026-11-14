<?php

declare(strict_types=1);

namespace App\Domain\Classification\Actions;

use App\Domain\Classification\Enums\MatchType;
use App\Domain\Classification\Exceptions\ClassificationRuleConflict;
use App\Domain\Classification\Models\ClassificationRule;
use App\Domain\Classification\Services\ClassificationRuleConflictDetector;
use App\Domain\Classification\Services\ClassificationRuleMatcher;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use InvalidArgumentException;

/**
 * Crea una regla del tenant activo.
 *
 * Antes de guardar valida la expresión regular y detecta colisiones con
 * reglas activas de la misma prioridad. Guardar igual requiere
 * `confirm_conflict`, decisión explícita del usuario (US-19).
 */
final readonly class CreateClassificationRule
{
    public function __construct(private ClassificationRuleConflictDetector $detector) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor): ClassificationRule
    {
        $matchType = MatchType::from((string) $data['match_type']);
        $pattern = trim((string) $data['pattern']);
        $priority = (int) ($data['priority'] ?? 100);

        $this->assertValidPattern($matchType, $pattern);

        $conflicts = $this->detector->detect($matchType, $pattern, $priority);

        if ($conflicts !== [] && ! (bool) ($data['confirm_conflict'] ?? false)) {
            throw new ClassificationRuleConflict($conflicts);
        }

        return ClassificationRule::query()->create([
            'tenant_id' => app(TenantContext::class)->id(),
            'name' => trim((string) $data['name']),
            'priority' => $priority,
            'match_type' => $matchType,
            'pattern' => $pattern,
            'expense_category_id' => (int) $data['expense_category_id'],
            'is_active' => (bool) ($data['is_active'] ?? true),
            'created_by' => $actor->getKey(),
        ]);
    }

    private function assertValidPattern(MatchType $matchType, string $pattern): void
    {
        if ($pattern === '') {
            throw new InvalidArgumentException('El patrón de la regla no puede estar vacío.');
        }

        if ($matchType->isRegex() && ! ClassificationRuleMatcher::isValidRegex($pattern)) {
            throw new InvalidArgumentException('La expresión regular de la regla no es válida.');
        }
    }
}
