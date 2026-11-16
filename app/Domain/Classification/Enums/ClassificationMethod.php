<?php

declare(strict_types=1);

namespace App\Domain\Classification\Enums;

/**
 * Origen de una clasificación.
 *
 * `rule` y `cache` no consumen presupuesto de IA. `manual` queda reservado
 * para una clasificación creada por una persona sin sugerencia previa; la
 * revisión de F3-05 conserva el origen y solo marca `reviewed_by`/`status`.
 */
enum ClassificationMethod: string
{
    case Rule = 'rule';
    case Cache = 'cache';
    case Llm = 'llm';
    case Manual = 'manual';
}
