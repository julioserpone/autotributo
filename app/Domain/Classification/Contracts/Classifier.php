<?php

declare(strict_types=1);

namespace App\Domain\Classification\Contracts;

use App\Domain\Classification\Data\ClassificationRequest;
use App\Domain\Classification\Data\ClassifierResult;
use App\Domain\Classification\Exceptions\ClassifierUnavailable;
use App\Domain\Classification\Exceptions\InvalidClassifierOutput;

/**
 * Sugiere una categoría de gasto para un ítem de comprobante.
 *
 * `Domain` depende de este contrato, nunca de un SDK concreto. Los adaptadores
 * viven en `App\Integrations\Ai` y solo traducen HTTP; la validación de la
 * salida y la resiliencia se aplican alrededor del clasificador elegido.
 */
interface Classifier
{
    /**
     * @throws ClassifierUnavailable
     * @throws InvalidClassifierOutput
     */
    public function classify(ClassificationRequest $request): ClassifierResult;
}
