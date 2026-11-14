<?php

declare(strict_types=1);

namespace App\Domain\Classification\Enums;

/**
 * Resultado de un intento de clasificación con IA registrado en `llm_calls`.
 *
 * `budget_exceeded` no representa una llamada al proveedor: se persiste para
 * dejar el motivo de la degradación a revisión, como exige US-21.
 */
enum LlmCallStatus: string
{
    case Success = 'success';
    case InvalidOutput = 'invalid_output';
    case Unavailable = 'unavailable';
    case BudgetExceeded = 'budget_exceeded';
}
