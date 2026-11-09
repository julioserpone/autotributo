<?php

declare(strict_types=1);

namespace App\Domain\Companies\Actions;

use App\Domain\Companies\Models\Company;

/**
 * Actualiza el perfil tributario de una empresa.
 *
 * Solo se pasan los atributos validados por el FormRequest; el `tenant_id`
 * y el estado no forman parte del payload editable.
 */
final readonly class UpdateCompany
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Company $company, array $attributes): Company
    {
        $company->fill($attributes);
        $company->save();

        return $company;
    }
}
