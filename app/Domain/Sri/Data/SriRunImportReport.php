<?php

declare(strict_types=1);

namespace App\Domain\Sri\Data;

use App\Domain\Sri\Enums\SriSyncRunStatus;

/**
 * Resumen de importar un run.
 *
 * `failed` cuenta los comprobantes que no se pudieron consultar o interpretar;
 * `skipped` los que ya estaban guardados con el mismo XML.
 */
final readonly class SriRunImportReport
{
    public function __construct(
        public int $found,
        public int $imported,
        public int $updated,
        public int $skipped,
        public int $failed,
        public SriSyncRunStatus $status,
    ) {}

    public function stored(): int
    {
        return $this->imported + $this->updated;
    }

    /**
     * @return array{found: int, imported: int, updated: int, skipped: int, failed: int}
     */
    public function toTotals(): array
    {
        return [
            'found' => $this->found,
            'imported' => $this->imported,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
        ];
    }
}
