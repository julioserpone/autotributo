<?php

declare(strict_types=1);

namespace App\Domain\Classification\Data;

use InvalidArgumentException;

/**
 * Salida validada del clasificador.
 *
 * La confianza siempre está en el rango 0..1 y la categoría pertenece al
 * catálogo vigente. Cualquier otra forma se rechaza antes de llegar aquí.
 */
final readonly class ClassificationSuggestion
{
    public function __construct(
        public string $categoryCode,
        public float $confidence,
        public string $reason,
    ) {
        if (trim($this->categoryCode) === '') {
            throw new InvalidArgumentException('La categoría sugerida no puede estar vacía.');
        }

        if (! is_finite($this->confidence) || $this->confidence < 0.0 || $this->confidence > 1.0) {
            throw new InvalidArgumentException('La confianza sugerida debe estar entre 0 y 1.');
        }

        if (trim($this->reason) === '') {
            throw new InvalidArgumentException('La sugerencia debe explicar brevemente su motivo.');
        }
    }
}
