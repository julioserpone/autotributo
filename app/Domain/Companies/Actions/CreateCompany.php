<?php

declare(strict_types=1);

namespace App\Domain\Companies\Actions;

use App\Domain\Companies\Models\Company;
use App\Models\User;

/**
 * Crea un contribuyente en el tenant activo.
 *
 * El `tenant_id` lo aporta el trait `BelongsToTenant`; el request nunca lo
 * acepta. El actor se guarda para auditoría.
 */
final readonly class CreateCompany
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes, User $actor): Company
    {
        $attributes['created_by'] = $actor->getKey();
        $attributes['status'] = Company::STATUS_ACTIVE;

        return Company::query()->create($attributes);
    }
}
