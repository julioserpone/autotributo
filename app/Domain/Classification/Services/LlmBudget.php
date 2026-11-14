<?php

declare(strict_types=1);

namespace App\Domain\Classification\Services;

use App\Domain\Classification\Models\LlmCall;

/**
 * Presupuesto mensual de IA por tenant.
 *
 * Suma `cost_micros` del mes en curso (enteros) y lo compara con el límite
 * configurado. Un límite de 0 significa «sin límite»: es el valor de los
 * entornos de prueba y permite desactivar la política sin cambiar código.
 *
 * La comprobación ocurre antes de llamar al proveedor; varias llamadas
 * concurrentes pueden sobrepasar el límite por una fracción del coste de un
 * ítem, aceptable para el MVP y coherente con el presupuesto mensual.
 */
final readonly class LlmBudget
{
    public function __construct(
        private int $monthlyLimitMicros,
        private float $warningRatio = 0.8,
    ) {}

    public function limitMicros(): int
    {
        return $this->monthlyLimitMicros;
    }

    public function isUnlimited(): bool
    {
        return $this->monthlyLimitMicros <= 0;
    }

    public function spentMicros(int $tenantId): int
    {
        $spent = LlmCall::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('cost_micros');

        return (int) $spent;
    }

    public function isExhausted(int $tenantId): bool
    {
        return ! $this->isUnlimited() && $this->spentMicros($tenantId) >= $this->monthlyLimitMicros;
    }

    public function isWarningReached(int $tenantId): bool
    {
        if ($this->isUnlimited()) {
            return false;
        }

        $threshold = (int) ceil($this->monthlyLimitMicros * $this->warningRatio);

        return $this->spentMicros($tenantId) >= $threshold;
    }
}
