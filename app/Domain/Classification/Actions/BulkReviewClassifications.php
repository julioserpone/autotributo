<?php

declare(strict_types=1);

namespace App\Domain\Classification\Actions;

use App\Domain\Classification\Data\BulkReviewResult;
use App\Domain\Classification\Data\ClassificationReviewData;
use App\Domain\Classification\Models\Classification;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Aplica la misma decisión a un lote de clasificaciones (F3-05).
 *
 * El lote es parcial por diseño: los ids que no existen en el tenant activo
 * se informan como `not_found` y el resto se revisa igual. Un fallo
 * inesperado en una fila no revierte las anteriores; el lote máximo lo impone
 * el FormRequest (100).
 */
final readonly class BulkReviewClassifications
{
    public function __construct(
        private ReviewClassification $review,
    ) {}

    /**
     * @param  list<int>  $ids
     */
    public function handle(array $ids, ClassificationReviewData $data, User $actor): BulkReviewResult
    {
        $found = Classification::query()
            ->whereKey($ids)
            ->get()
            ->keyBy(static fn (Classification $classification): int => (int) $classification->getKey());

        $updated = 0;
        /** @var list<array{id:int, reason:string}> $failed */
        $failed = [];

        foreach ($ids as $id) {
            $classification = $found->get($id);

            if (! $classification instanceof Classification) {
                $failed[] = ['id' => $id, 'reason' => 'not_found'];

                continue;
            }

            try {
                $this->review->handle($classification, $data, $actor);
                $updated++;
            } catch (Throwable $exception) {
                // Sin detalles internos en la respuesta: el motivo real queda
                // en el log con el id para poder investigarlo.
                Log::warning('classification.bulk_review_failed', [
                    'tenant_id' => $classification->tenant_id,
                    'classification_id' => $id,
                    'reason' => $exception::class,
                ]);

                $failed[] = ['id' => $id, 'reason' => 'review_failed'];
            }
        }

        return new BulkReviewResult(updated: $updated, failed: $failed);
    }
}
