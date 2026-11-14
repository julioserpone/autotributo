<?php

declare(strict_types=1);

namespace App\Domain\Classification\Enums;

/**
 * Origen de una clasificación.
 *
 * `rule` y `cache` no consumen presupuesto de IA; `manual` lo registra la
 * revisión humana de F3-05.
 */
enum ClassificationMethod: string
{
    case Rule = 'rule';
    case Cache = 'cache';
    case Llm = 'llm';
    case Manual = 'manual';
}
