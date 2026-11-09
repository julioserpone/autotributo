<?php

declare(strict_types=1);

namespace App\Domain\Companies\Actions\Credentials;

use App\Domain\Companies\Models\SriCredential;
use Illuminate\Support\Carbon;

/**
 * Refleja el resultado de un uso de la credencial en el portal.
 *
 * Un fallo de autenticación incrementa `failure_count` y marca la credencial
 * como `invalid` tras tres fallos; un éxito la devuelve a `active` y reinicia
 * el contador. Los errores de transporte **no** deben llegar aquí: no son
 * culpa de la credencial.
 */
final readonly class MarkCredentialStatus
{
    public function __construct(private int $failureThreshold = 3) {}

    public function success(SriCredential $credential): void
    {
        $credential->forceFill([
            'status' => SriCredential::STATUS_ACTIVE,
            'failure_count' => 0,
            'last_success_at' => Carbon::now(),
        ])->save();
    }

    public function failure(SriCredential $credential): void
    {
        $failures = $credential->failure_count + 1;

        $credential->forceFill([
            'failure_count' => $failures,
            'last_failure_at' => Carbon::now(),
            'status' => $failures >= $this->failureThreshold
                ? SriCredential::STATUS_INVALID
                : $credential->status,
        ])->save();
    }
}
