<?php

declare(strict_types=1);

namespace App\Domain\Classification\Data;

/**
 * Resultado de una revisión masiva.
 *
 * La acción es deliberadamente parcial: un id inexistente (o de otro tenant)
 * no revierte las decisiones ya tomadas sobre el resto del lote. La UI
 * muestra `failed` para que la persona reintente solo lo que falló.
 */
final readonly class BulkReviewResult
{
    /**
     * @param  list<array{id:int, reason:string}>  $failed
     */
    public function __construct(
        public int $updated,
        public array $failed = [],
    ) {}

    /**
     * @return array{updated:int, failed:list<array{id:int, reason:string}>}
     */
    public function toArray(): array
    {
        return [
            'updated' => $this->updated,
            'failed' => $this->failed,
        ];
    }
}
