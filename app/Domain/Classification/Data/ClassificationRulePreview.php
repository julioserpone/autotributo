<?php

declare(strict_types=1);

namespace App\Domain\Classification\Data;

/**
 * Resultado de un dry-run de regla: impacto antes de guardar.
 */
final readonly class ClassificationRulePreview
{
    /**
     * @param  list<array<string, mixed>>  $sample
     * @param  list<RuleConflictData>  $conflicts
     */
    public function __construct(
        public int $matchesCount,
        public int $scannedCount,
        public array $sample,
        public array $conflicts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'matches_count' => $this->matchesCount,
            'scanned_count' => $this->scannedCount,
            'sample' => $this->sample,
            'conflicts' => array_map(
                static fn (RuleConflictData $conflict): array => $conflict->toArray(),
                $this->conflicts,
            ),
        ];
    }
}
