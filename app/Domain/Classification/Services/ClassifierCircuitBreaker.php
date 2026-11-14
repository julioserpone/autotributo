<?php

declare(strict_types=1);

namespace App\Domain\Classification\Services;

use App\Domain\Classification\Exceptions\ClassifierUnavailable;
use Illuminate\Support\Facades\Cache;

/**
 * Corta las llamadas a la IA cuando el proveedor falla repetidamente.
 *
 * El estado vive en caché para sobrevivir entre requests y workers. La clave
 * incluye el tenant: en modo single (ADR-0001) solo hay uno, pero si se activa
 * multi-tenancy cada estudio conserva su propio circuito. La limitación es que
 * la caché es local al despliegue; con varios nodos cada uno aprende por su
 * cuenta (aceptable para F3-03).
 */
final readonly class ClassifierCircuitBreaker
{
    public function __construct(
        private int $failureThreshold,
        private int $cooldownSeconds,
    ) {}

    public function guard(string $scope): void
    {
        if ($this->isOpen($scope)) {
            throw ClassifierUnavailable::circuitOpen();
        }
    }

    public function isOpen(string $scope): bool
    {
        return Cache::get($this->openKey($scope)) === true;
    }

    public function recordFailure(string $scope): void
    {
        $failures = (int) Cache::get($this->failureKey($scope), 0) + 1;

        if ($failures >= max(1, $this->failureThreshold)) {
            Cache::put(
                $this->openKey($scope),
                true,
                now()->addSeconds($this->cooldown()),
            );
            Cache::forget($this->failureKey($scope));

            return;
        }

        Cache::put(
            $this->failureKey($scope),
            $failures,
            now()->addSeconds($this->cooldown()),
        );
    }

    public function recordSuccess(string $scope): void
    {
        $this->reset($scope);
    }

    public function reset(string $scope): void
    {
        Cache::forget($this->failureKey($scope));
        Cache::forget($this->openKey($scope));
    }

    private function cooldown(): int
    {
        return max(1, $this->cooldownSeconds);
    }

    private function openKey(string $scope): string
    {
        return "ai:classifier:circuit:open:{$scope}";
    }

    private function failureKey(string $scope): string
    {
        return "ai:classifier:circuit:failures:{$scope}";
    }
}
