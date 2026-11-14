<?php

declare(strict_types=1);

namespace App\Domain\Classification\Services;

use App\Domain\Classification\Contracts\Classifier;
use App\Domain\Classification\Data\ClassificationRequest;
use App\Domain\Classification\Data\ClassifierResult;
use App\Domain\Classification\Exceptions\ClassifierUnavailable;
use App\Domain\Classification\Exceptions\InvalidClassifierOutput;
use App\Domain\Tenancy\TenantContext;
use LogicException;

/**
 * Añade reintento y circuit breaker alrededor del proveedor elegido.
 *
 * Solo se reintenta una salida inválida: un timeout, un 429 o un 5xx no se
 * repiten porque el proveedor ya está degradado y cada llamada cuesta. El
 * circuito se abre tras N fallos y se cierra solo al agotar el enfriamiento o
 * cuando una llamada vuelve a tener éxito.
 */
final readonly class ResilientClassifier implements Classifier
{
    public function __construct(
        private Classifier $inner,
        private ClassifierCircuitBreaker $circuit,
        private int $maxAttempts,
        private TenantContext $tenants,
    ) {}

    public function classify(ClassificationRequest $request): ClassifierResult
    {
        $scope = $this->scope();
        $this->circuit->guard($scope);
        $attempts = max(1, $this->maxAttempts);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $result = $this->inner->classify($request);
                $this->circuit->recordSuccess($scope);

                return $result;
            } catch (InvalidClassifierOutput $exception) {
                if ($attempt < $attempts) {
                    continue;
                }

                $this->circuit->recordFailure($scope);

                throw $exception;
            } catch (ClassifierUnavailable $exception) {
                $this->circuit->recordFailure($scope);

                throw $exception;
            }
        }

        throw new LogicException('El clasificador agotó sus intentos sin devolver un resultado.');
    }

    private function scope(): string
    {
        $tenant = $this->tenants->current();

        return $tenant === null ? 'global' : (string) $tenant->getTenantKey();
    }
}
