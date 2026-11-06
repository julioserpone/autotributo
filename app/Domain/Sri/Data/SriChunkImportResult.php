<?php

declare(strict_types=1);

namespace App\Domain\Sri\Data;

/**
 * Resultado de importar un lote de claves de un run.
 *
 * Se agrega lote a lote; el run conserva el total y cada comprobante guarda
 * su propio resultado en `sri_sync_run_files`.
 */
final readonly class SriChunkImportResult
{
    public function __construct(
        public int $found = 0,
        public int $imported = 0,
        public int $updated = 0,
        public int $skipped = 0,
        public int $failed = 0,
        public int $fromS3 = 0,
        public int $fromSri = 0,
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    public function merge(self $other): self
    {
        return new self(
            $this->found + $other->found,
            $this->imported + $other->imported,
            $this->updated + $other->updated,
            $this->skipped + $other->skipped,
            $this->failed + $other->failed,
            $this->fromS3 + $other->fromS3,
            $this->fromSri + $other->fromSri,
        );
    }

    public function stored(): int
    {
        return $this->imported + $this->updated + $this->skipped;
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
