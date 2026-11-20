<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Enums;

/**
 * Códigos de hallazgo fiscal detectados por F3-06.
 *
 * Los códigos reservados (`OUT_OF_PERIOD`, `MISSING_RETENTION`,
 * `EXPENSE_CAP_EXCEEDED`) se declaran para mantener el contrato estable, pero
 * su detección se implementa en fases posteriores (F5).
 */
enum FiscalRiskCode: string
{
    case TotalsMismatch = 'TOTALS_MISMATCH';
    case InvalidRuc = 'INVALID_RUC';
    case DuplicateSequential = 'DUPLICATE_SEQUENTIAL';
    case GhostCompany = 'GHOST_COMPANY';

    // Reservados (detección diferida).
    case OutOfPeriod = 'OUT_OF_PERIOD';
    case MissingRetention = 'MISSING_RETENTION';
    case ExpenseCapExceeded = 'EXPENSE_CAP_EXCEEDED';
}