<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Data;

use App\Domain\Fiscal\Models\FiscalFinding;

/**
 * Resultado de una ejecución de detección de riesgos fiscales.
 */
final readonly class FiscalRiskDetectionResult
{
    /**
     * @param  list<FiscalFinding>  $findings
     */
    public function __construct(
        public int $created,
        public int $updated,
        public int $resolved,
        public array $findings,
    ) {}

    public function total(): int
    {
        return count($this->findings);
    }
}
