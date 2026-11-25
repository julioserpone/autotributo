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
            // Subcategorías: dan granularidad al clasificador y a los reportes
            // por rubro sin cambiar las reglas fiscales de los padres.
            $this->child('alimentacion_supermercado', 'Supermercado y comisariato', 'alimentacion', 11),
            $this->child('alimentacion_restaurante', 'Restaurantes', 'alimentacion', 12),
            $this->child('vivienda_alquiler', 'Arriendo', 'vivienda', 21),
            $this->child('vivienda_servicios', 'Servicios básicos del hogar', 'vivienda', 22),
            $this->child('vivienda_mantenimiento', 'Mantenimiento y reparaciones', 'vivienda', 23),
            $this->child('salud_medicina', 'Medicinas', 'salud', 31),
            $this->child('salud_consultas', 'Consultas y tratamientos', 'salud', 32),
            $this->child('salud_seguros', 'Seguros de salud', 'salud', 33),
            $this->child('educacion_matriculas', 'Matrículas y pensiones', 'educacion', 41),
            $this->child('educacion_utiles', 'Útiles y textos', 'educacion', 42),
            $this->child('vestimenta_ropa', 'Ropa y calzado', 'vestimenta', 51),
            $this->child('turismo_hospedaje', 'Hospedaje y transporte', 'turismo', 61),
            $this->child('operativo_comunicaciones', 'Telecomunicaciones e internet', 'operativo', 71),
            $this->child('operativo_combustible', 'Combustible', 'operativo', 72),
            $this->child('operativo_servicios', 'Servicios profesionales y técnicos', 'operativo', 73),
            $this->child('operativo_insumos', 'Insumos y materiales', 'operativo', 74),
            $this->child('operativo_arriendos', 'Arriendos del negocio', 'operativo', 75),
            $this->child('activo_equipos', 'Equipos y tecnología', 'activo_fijo', 81),
            $this->child('activo_vehiculos', 'Vehículos', 'activo_fijo', 82),
        ];
    }

    /**
     * Fila de subcategoría con herencia de deducibilidad del padre.
     *
     * @return array<string, mixed>
     */
    private function child(string $code, string $name, string $parentCode, int $displayOrder): array
    {
        return [
            'code' => $code,
            'name' => $name,
            'parent_code' => $parentCode,
            'is_personal_deductible' => false,
            'is_business_deductible' => false,
            'icon' => null,
            'display_order' => $displayOrder,
        ];
    }
}
