<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

final class TenantNotFound extends RuntimeException
{
    public static function forSlug(string $slug): self
    {
        return new self("No existe el tenant por defecto con slug [{$slug}]. Ejecuta `php artisan db:seed` o el provisionador.");
    }
}
