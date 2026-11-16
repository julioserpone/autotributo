<?php

declare(strict_types=1);

namespace App\Domain\Classification\Data;

use App\Domain\Classification\Enums\ReviewDecision;

/**
 * Decisión de revisión humana (F3-05) ya validada por el FormRequest.
 *
 * `expenseCategoryId` solo tiene sentido al aceptar: permite corregir la
 * categoría sugerida en el mismo paso.
 */
final readonly class ClassificationReviewData
{
    public function __construct(
        public ReviewDecision $decision,
        public ?int $expenseCategoryId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $decision = $data['decision'] ?? null;

        return new self(
            decision: $decision instanceof ReviewDecision
                ? $decision
                : ReviewDecision::from((string) $decision),
            expenseCategoryId: isset($data['expense_category_id'])
                ? (int) $data['expense_category_id']
                : null,
        );
    }

    public function isAccept(): bool
    {
        return $this->decision === ReviewDecision::Accept;
    }
}
