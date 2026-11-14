<?php

declare(strict_types=1);

namespace App\Domain\Classification\Catalogs;

/**
 * Catálogo inicial de categorías de gasto.
 *
 * El árbol cubre las deducciones personales y los gastos del negocio. Las
 * banderas de deducibilidad son criterio fiscal y quedan pendientes de firma
 * del contador en `docs/fiscal-signoff.md`.
 */
final class ExpenseCategoryData
{
    public const SOURCE = 'Autotributo - catálogo inicial de categorías de gasto (pendiente de firma del contador)';

    /**
     * @return list<array<string, mixed>>
     */
    public function categories(): array
    {
        return [
            [
                'code' => 'alimentacion',
                'name' => 'Alimentación',
                'parent_code' => null,
                'is_personal_deductible' => true,
                'is_business_deductible' => false,
                'icon' => 'utensils',
                'display_order' => 10,
            ],
            [
                'code' => 'vivienda',
                'name' => 'Vivienda',
                'parent_code' => null,
                'is_personal_deductible' => true,
                'is_business_deductible' => false,
                'icon' => 'home',
                'display_order' => 20,
            ],
            [
                'code' => 'salud',
                'name' => 'Salud',
                'parent_code' => null,
                'is_personal_deductible' => true,
                'is_business_deductible' => false,
                'icon' => 'heart-pulse',
                'display_order' => 30,
            ],
            [
                'code' => 'educacion',
                'name' => 'Educación',
                'parent_code' => null,
                'is_personal_deductible' => true,
                'is_business_deductible' => false,
                'icon' => 'graduation-cap',
                'display_order' => 40,
            ],
            [
                'code' => 'vestimenta',
                'name' => 'Vestimenta',
                'parent_code' => null,
                'is_personal_deductible' => true,
                'is_business_deductible' => false,
                'icon' => 'shirt',
                'display_order' => 50,
            ],
            [
                'code' => 'turismo',
                'name' => 'Turismo',
                'parent_code' => null,
                'is_personal_deductible' => true,
                'is_business_deductible' => false,
                'icon' => 'plane',
                'display_order' => 60,
            ],
            [
                'code' => 'operativo',
                'name' => 'Gastos operativos',
                'parent_code' => null,
                'is_personal_deductible' => false,
                'is_business_deductible' => true,
                'icon' => 'briefcase',
                'display_order' => 70,
            ],
            [
                'code' => 'activo_fijo',
                'name' => 'Activos fijos',
                'parent_code' => null,
                'is_personal_deductible' => false,
                'is_business_deductible' => true,
                'icon' => 'building',
                'display_order' => 80,
            ],
            [
                'code' => 'no_deducible',
                'name' => 'No deducible',
                'parent_code' => null,
                'is_personal_deductible' => false,
                'is_business_deductible' => false,
                'icon' => 'ban',
                'display_order' => 90,
            ],
        ];
    }
}
