<?php

declare(strict_types=1);

namespace App\Domain\Classification\Catalogs;

use App\Domain\Classification\Enums\MatchType;

/**
 * Reglas de clasificación base para emisores recurrentes.
 *
 * Son reglas **globales** (`tenant_id` nulo) y se aplican antes de consultar la
 * IA, así que ahorran llamadas y dan un resultado determinista en los
 * proveedores de siempre (comisariatos, farmacias, telecomunicaciones, agua,
 * peajes, seguros).
 *
 * Todas se declaran `is_active = false`: son una sugerencia de partida que el
 * estudio revisa y activa. Los RUC son de empresas reales, pero una regla
 * activa por defecto clasificaría gastos de un contribuyente sin que nadie lo
 * haya decidido; con la regla inactiva el ítem sigue su curso normal.
 *
 * `priority` bajo gana: las reglas del tenant siempre se evalúan antes que
 * estas (ver `ClassificationRuleRepository`).
 */
final readonly class ClassificationRuleData
{
    public const SOURCE = 'Autotributo - reglas base para emisores recurrentes (revisar antes de activar)';

    /**
     * @return list<array<string, mixed>>
     */
    public function rules(): array
    {
        return [
            $this->provider('Comisariato La Favorita', '1790016919001', 'comestibles', 100),
            $this->provider('Corporación El Rosado (Mi Comisariato)', '0990004196001', 'comestibles', 101),
            $this->provider('Nucopsa (Tiendas Industriales Asociadas)', '0991306498001', 'comestibles', 102),
            $this->provider('Farmacias Farcomed', '1790710319001', 'salud_farmacia', 200),
            $this->provider('Difare (farmacias)', '0990858322001', 'salud_farmacia', 201),
            $this->provider('Corporación Nacional de Telecomunicaciones', '1768152560001', 'servicios_telecom', 300),
            $this->provider('Onetelecom', '2390018293001', 'servicios_telecom', 301),
            $this->provider('Empresa Eléctrica Quito', '1790053881001', 'servicios_basicos', 310),
            $this->provider('Empresa Metropolitana de Agua Potable y Saneamiento', '1768154260001', 'servicios_basicos', 311),
            $this->provider('Panamericana Vial (peajes)', '1791317025001', 'transporte_peaje', 400),
            $this->provider('Seguros del Pichincha', '1791289927001', 'seguros', 500),
            $this->provider('Chubb Seguros Ecuador', '1790516008001', 'seguros', 501),
            $this->provider('Almacenes De Prati', '0990011214001', 'vestimenta_hogar', 600),
            $this->provider('Homecenters Ecuatorianos (Kywi)', '1793194511001', 'ferreteria_hogar', 601),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function provider(string $name, string $ruc, string $categoryCode, int $priority): array
    {
        return [
            'name' => $name,
            'priority' => $priority,
            'match_type' => MatchType::ProviderRuc,
            'pattern' => $ruc,
            'category_code' => $categoryCode,
        ];
    }

    /**
     * Categorías que usan estas reglas.
     *
     * Se declaran aparte de `ExpenseCategoryData` porque son de uso operativo
     * (agrupar un proveedor frecuente), no categorías fiscales del formulario
     * de gastos personales: llevan deducibilidad propia y revisitada.
     *
     * @return list<array<string, mixed>>
     */
    public function categories(): array
    {
        $rows = [
            ['code' => 'comestibles', 'name' => 'Comestibles y víveres', 'personal' => true, 'business' => true, 'icon' => 'shopping-basket', 'order' => 110],
            ['code' => 'salud_farmacia', 'name' => 'Farmacia', 'personal' => true, 'business' => false, 'icon' => 'pill', 'order' => 34],
            ['code' => 'servicios_telecom', 'name' => 'Telecomunicaciones e internet', 'personal' => false, 'business' => true, 'icon' => 'wifi', 'order' => 76],
            ['code' => 'servicios_basicos', 'name' => 'Servicios básicos', 'personal' => true, 'business' => true, 'icon' => 'plug', 'order' => 24],
            ['code' => 'transporte_peaje', 'name' => 'Peajes y transporte', 'personal' => false, 'business' => true, 'icon' => 'car', 'order' => 77],
            ['code' => 'seguros', 'name' => 'Seguros', 'personal' => true, 'business' => true, 'icon' => 'shield', 'order' => 35],
            ['code' => 'vestimenta_hogar', 'name' => 'Vestimenta y hogar', 'personal' => true, 'business' => false, 'icon' => 'shirt', 'order' => 52],
            ['code' => 'ferreteria_hogar', 'name' => 'Ferretería y hogar', 'personal' => false, 'business' => true, 'icon' => 'wrench', 'order' => 25],
        ];

        /** @var list<array<string, mixed>> $categories */
        $categories = [];

        foreach ($rows as $row) {
            $categories[] = [
                'code' => $row['code'],
                'name' => $row['name'],
                'parent_code' => null,
                'is_personal_deductible' => $row['personal'],
                'is_business_deductible' => $row['business'],
                'icon' => $row['icon'],
                'display_order' => $row['order'],
            ];
        }

        return $categories;
    }
}
