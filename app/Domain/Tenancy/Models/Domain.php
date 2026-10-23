<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use Stancl\Tenancy\Database\Models\Domain as BaseDomain;

/**
 * Dominio asociado a un tenant. En el MVP single-tenant no se resuelve por
 * dominio; la tabla y el modelo existen para activar el modo multi sin
 * cambiar el esquema (ADR-0006).
 */
final class Domain extends BaseDomain
{
    /** @var array<string, string> */
    protected $casts = [
        'is_primary' => 'boolean',
    ];
}
