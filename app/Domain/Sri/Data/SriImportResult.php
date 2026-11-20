<?php

declare(strict_types=1);

namespace App\Domain\Sri\Data;

use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Domain\Sri\Enums\SriImportOutcome;

/**
 * Comprobante persistido junto con lo que ocurrió con él.
 */
final readonly class SriImportResult
{
    public function __construct(
        public FiscalDocument $fiscal_document,
        public SriImportOutcome $outcome,
    ) {}

    public function wasStored(): bool
    {
        return $this->outcome->wasStored();
    }
}
