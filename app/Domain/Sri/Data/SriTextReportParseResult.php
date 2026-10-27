<?php

declare(strict_types=1);

namespace App\Domain\Sri\Data;

/**
 * Resultado de leer el TXT de claves del robot.
 *
 * Una línea inválida no invalida el archivo completo: el robot publica lo que
 * descargó y la app decide después si el run puede continuar. El parser nunca
 * lanza por contenido, solo devuelve el detalle.
 */
final readonly class SriTextReportParseResult
{
    /**
     * @param  list<SriAccessKey>  $accessKeys  Claves válidas (deduplicadas, en orden de aparición)
     * @param  list<array{line: int, value: string, reason: string}>  $invalidLines
     */
    public function __construct(
        public string $period,
        public array $accessKeys,
        public array $invalidLines = [],
        public int $totalLines = 0,
        public int $emptyLines = 0,
        public int $duplicateLines = 0,
    ) {}

    /**
     * @return list<string>
     */
    public function values(): array
    {
        return array_map(
            static fn (SriAccessKey $key): string => $key->value,
            $this->accessKeys,
        );
    }

    public function count(): int
    {
        return count($this->accessKeys);
    }

    public function isEmpty(): bool
    {
        return $this->accessKeys === [];
    }

    public function hasInvalidLines(): bool
    {
        return $this->invalidLines !== [];
    }
}
