<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

final class TenantContextNotSet extends RuntimeException
{
    public static function forModel(string $model): self
    {
        return new self("No hay tenant activo para consultar o crear {$model}.");
    }

    public static function forJob(string $job): self
    {
        return new self("El job {$job} se ejecutó sin contexto de tenant.");
    }

    public static function forRequest(string $path): self
    {
        return new self("La ruta {$path} requiere un tenant activo.");
    }
}
