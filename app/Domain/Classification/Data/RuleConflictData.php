<?php

declare(strict_types=1);

namespace App\Domain\Classification\Data;

/**
 * Regla activa que colisiona con una regla candidata.
 */
final readonly class RuleConflictData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $matchType,
        public string $pattern,
        public int $priority,
        public string $reason,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'match_type' => $this->matchType,
            'pattern' => $this->pattern,
            'priority' => $this->priority,
            'reason' => $this->reason,
        ];
    }
}
