<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Classification\Actions\SyncClassificationRules;
use Illuminate\Database\Seeder;

/**
 * Carga las reglas base para emisores recurrentes, inactivas.
 *
 * Activar una regla es una decisión del estudio: se hace desde la UI, no en el
 * seeder, para que `db:seed` nunca cambie la clasificación de un contribuyente
 * por su cuenta.
 */
final class ClassificationRuleSeeder extends Seeder
{
    public function run(): void
    {
        $counts = app(SyncClassificationRules::class)->handle();

        $this->command?->line(sprintf(
            'Reglas de clasificación base: %d creadas, %d actualizadas, %d sin cambios, %d omitidas (sin categoría).',
            $counts['created'],
            $counts['updated'],
            $counts['unchanged'],
            $counts['skipped'],
        ));
    }
}
