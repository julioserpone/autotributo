<?php

declare(strict_types=1);

namespace App\Domain\Classification\Data;

use InvalidArgumentException;

/**
 * Categoría del catálogo que el clasificador puede proponer.
 *
 * El `id` se usa para persistir la clasificación; el modelo solo recibe
 * `code` y `name`. Así el LLM nunca ve identificadores internos.
 */
final readonly class ClassificationCategory
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
    ) {
        if ($this->id <= 0) {
            throw new InvalidArgumentException('La categoría debe tener un identificador válido.');
        }

        if (trim($this->code) === '') {
            throw new InvalidArgumentException('El código de la categoría no puede estar vacío.');
        }

        if (trim($this->name) === '') {
            throw new InvalidArgumentException('El nombre de la categoría no puede estar vacío.');
        }
    }
}
