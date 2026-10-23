<?php

declare(strict_types=1);

namespace App\Domain\Sri\Data;

use App\Domain\Sri\Models\SriSyncRun;
use InvalidArgumentException;

/**
 * Runs creados por una misma solicitud de sincronización.
 *
 * Una solicitud con más de 93 días se divide en varias invocaciones; cada una
 * tiene su propio `SriSyncRun` e `idempotency_key`.
 */
final readonly class SriSyncBatch
{
    /**
     * @param  list<SriSyncRun>  $runs
     */
    public function __construct(public array $runs)
    {
        if ($this->runs === []) {
            throw new InvalidArgumentException('El lote de sincronización debe tener al menos un run.');
        }
    }

    public function first(): SriSyncRun
    {
        return $this->runs[0];
    }

    public function count(): int
    {
        return count($this->runs);
    }
}
