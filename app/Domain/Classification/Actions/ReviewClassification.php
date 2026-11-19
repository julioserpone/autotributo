<?php

declare(strict_types=1);

namespace App\Domain\Classification\Actions;

use App\Domain\Classification\Data\ClassificationReviewData;
use App\Domain\Classification\Enums\ClassificationStatus;
use App\Domain\Classification\Models\Classification;
use App\Domain\FiscalDocuments\Models\FiscalDocumentItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Registra la decisión de una persona sobre una clasificación (F3-05).
 *
 * Aceptar confirma la categoría —la sugerida o una elegida en el mismo paso—
 * y deja el ítem como `manual`. Rechazar deja el ítem sin categoría y en
 * `pending`, para que una regla posterior lo vuelva a intentar; la fila de
 * `classifications` conserva la categoría propuesta como evidencia.
 *
 * El origen (`method`, `rule_id`, `llm_call_id`, `prompt_version`) nunca se
 * reescribe: la revisión solo cambia el estado, la categoría y la firma
 * (`reviewed_by`, `reviewed_at`). Cada cambio queda en `audit_logs`.
 *
 * Revisar una clasificación ya revisada es válido y también se audita: una
 * persona puede corregir su propia decisión antes de declarar.
 */
final readonly class ReviewClassification
{
    public function handle(
        Classification $classification,
        ClassificationReviewData $data,
        User $actor,
    ): Classification {
        return DB::transaction(function () use ($classification, $data, $actor): Classification {
            $locked = Classification::query()
                ->whereKey($classification->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $item = FiscalDocumentItem::query()
                ->whereKey($locked->fiscal_document_item_id)
                ->lockForUpdate()
                ->firstOrFail();

            $accepted = $data->isAccept();

            $categoryId = $accepted
                ? ($data->expenseCategoryId ?? (int) $locked->expense_category_id)
                : null;

            $item->forceFill([
                'expense_category_id' => $categoryId,
                'classification_status' => $accepted
                    ? FiscalDocumentItem::STATUS_MANUAL
                    : FiscalDocumentItem::STATUS_PENDING,
            ])->save();

            $locked->forceFill([
                'status' => $accepted
                    ? ClassificationStatus::Accepted
                    : ClassificationStatus::Rejected,
                'expense_category_id' => $categoryId ?? $locked->expense_category_id,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
            ])->save();

            return $locked;
        });
    }
}
