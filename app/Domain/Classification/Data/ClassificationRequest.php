<?php

declare(strict_types=1);

namespace App\Domain\Classification\Data;

use InvalidArgumentException;

/**
 * Datos mínimos que recibe el clasificador para una línea del comprobante.
 *
 * No incluye RUC, nombres del emisor, XML ni totales: la descripción del ítem
 * y la actividad económica del emisor son suficientes para sugerir una
 * categoría y reducen la exposición de datos personales.
 */
final readonly class ClassificationRequest
{
    /** @var list<ClassificationCategory> */
    public array $categories;

    /**
     * @param  list<ClassificationCategory>  $categories
     */
    public function __construct(
        public string $description,
        public ?string $issuerActivity = null,
        array $categories = [],
    ) {
        if (trim($this->description) === '') {
            throw new InvalidArgumentException('La descripción del ítem no puede estar vacía.');
        }

        if ($categories === []) {
            throw new InvalidArgumentException('El clasificador necesita al menos una categoría permitida.');
        }

        $this->categories = array_values($categories);
    }

    /**
     * @return list<string>
     */
    public function categoryCodes(): array
    {
        return array_map(
            static fn (ClassificationCategory $category): string => $category->code,
            $this->categories,
        );
    }

    /**
     * @return array<string, string> Código => nombre.
     */
    public function categoryNames(): array
    {
        $names = [];

        foreach ($this->categories as $category) {
            $names[$category->code] = $category->name;
        }

        return $names;
    }
}
