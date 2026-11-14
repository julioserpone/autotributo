<?php

declare(strict_types=1);

namespace App\Domain\Classification\Actions;

use App\Domain\Classification\Models\ClassificationRule;

/**
 * Borra (soft delete) una regla del tenant.
 *
 * El borrado invalida la caché del catálogo desde el modelo; las
 * clasificaciones ya emitidas conservan `rule_id` nulo si la regla se purga.
 */
final readonly class DeleteClassificationRule
{
    public function handle(ClassificationRule $rule): void
    {
        $rule->delete();
    }
}
