<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Catalogs;

/**
 * Datos iniciales de los catálogos fiscales versionados.
 *
 * Cada fila es un parámetro fiscal con su vigencia. Las fuentes se guardan en
 * la propia fila (`source`, `published_at`, `checksum`) para que un cálculo
 * pueda explicar de dónde salió. Los valores quedan sujetos al registro de
 * `docs/fiscal-signoff.md` antes de cerrar una fase fiscal.
 */
final class FiscalCatalogData
{
    public const IVA_CODE = 'IVA';

    private const SOURCE_IVA = 'SRI - LRTI arts. 52-56, Ley Orgánica para enfrentar el conflicto armado interno (2024)';

    private const SOURCE_RETENTIONS = 'SRI - Resolución NAC-DGERCGC26-00000009 y tabla de retenciones 2026';

    private const SOURCE_FORM_104 = 'SRI - Guía para el llenado del Formulario 104 (IVA)';

    private const SOURCE_CAPS_2026 = 'SRI/INEC - Canasta Básica Familiar enero 2026 (USD 821,80)';

    /**
     * @return list<array<string, mixed>>
     */
    public function taxRates(): array
    {
        $rows = [
            [
                'code' => self::IVA_CODE,
                'sri_percentage_code' => '0',
                'rate' => '0.000000',
                'label' => 'Tarifa 0% de IVA (bienes y servicios exentos)',
                'applies_to' => null,
                'valid_from' => '2000-01-01',
                'valid_to' => null,
                'legal_basis' => 'LRTI arts. 54, 55 y 56',
                'published_at' => '2000-01-01',
            ],
            [
                'code' => self::IVA_CODE,
                'sri_percentage_code' => '12',
                'rate' => '12.000000',
                'label' => 'Tarifa general de IVA (12%)',
                'applies_to' => 'general',
                'valid_from' => '2000-01-01',
                'valid_to' => '2016-05-31',
                'legal_basis' => 'LRTI art. 65',
                'published_at' => '2000-01-01',
            ],
            [
                'code' => self::IVA_CODE,
                'sri_percentage_code' => '14',
                'rate' => '14.000000',
                'label' => 'Tarifa temporal de IVA (14%)',
                'applies_to' => 'general',
                'valid_from' => '2016-06-01',
                'valid_to' => '2017-05-31',
                'legal_basis' => 'Ley Orgánica de Solidaridad (2016)',
                'published_at' => '2016-06-01',
            ],
            [
                'code' => self::IVA_CODE,
                'sri_percentage_code' => '12',
                'rate' => '12.000000',
                'label' => 'Tarifa general de IVA (12%)',
                'applies_to' => 'general',
                'valid_from' => '2017-06-01',
                'valid_to' => '2024-03-31',
                'legal_basis' => 'LRTI art. 65',
                'published_at' => '2017-06-01',
            ],
            [
                'code' => self::IVA_CODE,
                'sri_percentage_code' => '15',
                'rate' => '15.000000',
                'label' => 'Tarifa general de IVA (15%)',
                'applies_to' => 'general',
                'valid_from' => '2024-04-01',
                'valid_to' => null,
                'legal_basis' => 'LRTI art. 65 y Ley Orgánica para enfrentar el conflicto armado interno (2024)',
                'published_at' => '2024-04-01',
            ],
            [
                'code' => self::IVA_CODE,
                'sri_percentage_code' => '5',
                'rate' => '5.000000',
                'label' => 'Tarifa de IVA para materiales de construcción (5%)',
                'applies_to' => 'construction_materials',
                'valid_from' => '2024-04-01',
                'valid_to' => null,
                'legal_basis' => 'Ley Orgánica para enfrentar el conflicto armado interno (2024)',
                'published_at' => '2024-04-01',
            ],
        ];

        return array_map(
            fn (array $row): array => self::withMetadata($row, self::SOURCE_IVA, (string) $row['published_at']),
            $rows,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function retentionRates(): array
    {
        $rows = [
            ['tax_type' => 'IR', 'code' => '303', 'rate' => '10.000000', 'label' => 'Honorarios profesionales y dietas', 'applies_to' => 'professional'],
            ['tax_type' => 'IR', 'code' => '304', 'rate' => '8.000000', 'label' => 'Servicios donde predomina el intelecto no relacionados con el título profesional', 'applies_to' => 'services'],
            ['tax_type' => 'IR', 'code' => '307', 'rate' => '2.000000', 'label' => 'Servicios donde predomina la mano de obra', 'applies_to' => 'services'],
            ['tax_type' => 'IR', 'code' => '308', 'rate' => '10.000000', 'label' => 'Utilización o aprovechamiento de la imagen o renombre', 'applies_to' => 'other'],
            ['tax_type' => 'IR', 'code' => '309', 'rate' => '1.750000', 'label' => 'Servicios prestados por medios de comunicación y agencias de publicidad', 'applies_to' => 'services'],
            ['tax_type' => 'IR', 'code' => '310', 'rate' => '1.000000', 'label' => 'Transporte privado de pasajeros o transporte público o privado de carga', 'applies_to' => 'services'],
            ['tax_type' => 'IR', 'code' => '311', 'rate' => '2.000000', 'label' => 'Pagos a través de liquidación de compra (nivel cultural o rusticidad)', 'applies_to' => 'goods'],
            ['tax_type' => 'IR', 'code' => '312', 'rate' => '1.750000', 'label' => 'Transferencia de bienes muebles de naturaleza corporal', 'applies_to' => 'goods'],
            ['tax_type' => 'IR', 'code' => '314', 'rate' => '8.000000', 'label' => 'Regalías, cánones, derechos de autor, marcas, patentes y similares', 'applies_to' => 'other'],
            ['tax_type' => 'IR', 'code' => '319', 'rate' => '1.750000', 'label' => 'Cuotas de arrendamiento mercantil (leasing)', 'applies_to' => 'leasing'],
            ['tax_type' => 'IR', 'code' => '320', 'rate' => '8.000000', 'label' => 'Arrendamiento de bienes inmuebles', 'applies_to' => 'rent'],
            ['tax_type' => 'IR', 'code' => '322', 'rate' => '1.750000', 'label' => 'Seguros y reaseguros (primas y cesiones)', 'applies_to' => 'insurance'],
            ['tax_type' => 'IR', 'code' => '323', 'rate' => '2.000000', 'label' => 'Rendimientos financieros', 'applies_to' => 'financial'],
            ['tax_type' => 'IR', 'code' => '332', 'rate' => '0.000000', 'label' => 'Otras compras de bienes y servicios no sujetas a retención', 'applies_to' => 'other'],
            ['tax_type' => 'IR', 'code' => '343', 'rate' => '1.000000', 'label' => 'Otras retenciones aplicables el 1%', 'applies_to' => 'other'],
            ['tax_type' => 'IR', 'code' => '344', 'rate' => '2.000000', 'label' => 'Otras retenciones aplicables el 2%', 'applies_to' => 'other'],
            ['tax_type' => 'IR', 'code' => '345', 'rate' => '8.000000', 'label' => 'Otras retenciones aplicables el 8%', 'applies_to' => 'other'],
            ['tax_type' => 'IVA', 'code' => '721', 'rate' => '10.000000', 'label' => 'Retención de IVA en adquisición de bienes muebles', 'applies_to' => 'goods'],
            ['tax_type' => 'IVA', 'code' => '723', 'rate' => '20.000000', 'label' => 'Retención de IVA en prestación de servicios', 'applies_to' => 'services'],
            ['tax_type' => 'IVA', 'code' => '725', 'rate' => '50.000000', 'label' => 'Retención de IVA en honorarios de personas naturales', 'applies_to' => 'professional'],
            ['tax_type' => 'IVA', 'code' => '727', 'rate' => '70.000000', 'label' => 'Retención de IVA en servicios donde predomina la mano de obra', 'applies_to' => 'services'],
            ['tax_type' => 'IVA', 'code' => '729', 'rate' => '100.000000', 'label' => 'Retención de IVA en liquidaciones de compra', 'applies_to' => 'goods'],
        ];

        return array_map(
            fn (array $row): array => self::withMetadata(
                $row + [
                    'valid_from' => '2026-01-01',
                    'valid_to' => null,
                    'legal_basis' => 'Resolución NAC-DGERCGC26-00000009',
                ],
                self::SOURCE_RETENTIONS,
                '2026-01-01',
            ),
            $rows,
        );
    }

    /**
     * Casilleros mínimos del Formulario 104. El catálogo completo se importa
     * desde el CSV oficial en F3-09.
     *
     * @return list<array<string, mixed>>
     */
    public function formLines(): array
    {
        $rows = [
            ['line_code' => '401', 'section' => 'ventas', 'label' => 'Ventas locales gravadas tarifa diferente de cero', 'data_type' => 'amount', 'is_required' => true, 'display_order' => 10],
            ['line_code' => '402', 'section' => 'ventas', 'label' => 'Ventas locales gravadas tarifa 5%', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 20],
            ['line_code' => '411', 'section' => 'ventas', 'label' => 'Ventas locales netas gravadas tarifa diferente de cero', 'data_type' => 'amount', 'is_required' => true, 'display_order' => 30],
            ['line_code' => '412', 'section' => 'ventas', 'label' => 'Ventas locales netas gravadas tarifa 5%', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 40],
            ['line_code' => '421', 'section' => 'ventas', 'label' => 'IVA generado en ventas tarifa diferente de cero', 'data_type' => 'amount', 'is_required' => true, 'display_order' => 50],
            ['line_code' => '422', 'section' => 'ventas', 'label' => 'IVA generado en ventas tarifa 5%', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 60],
            ['line_code' => '431', 'section' => 'ventas', 'label' => 'Transferencias de bienes y servicios no objeto o exentas de IVA', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 70],
            ['line_code' => '601', 'section' => 'resumen', 'label' => 'Impuesto causado', 'data_type' => 'amount', 'is_required' => true, 'display_order' => 200],
            ['line_code' => '602', 'section' => 'resumen', 'label' => 'Crédito tributario aplicable en este período', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 210],
            ['line_code' => '603', 'section' => 'resumen', 'label' => 'Compensación de IVA por ventas con medio electrónico y/o IVA devuelto', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 220],
            ['line_code' => '605', 'section' => 'resumen', 'label' => 'Saldo de crédito tributario del mes anterior por adquisiciones e importaciones', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 230],
            ['line_code' => '606', 'section' => 'resumen', 'label' => 'Saldo de crédito tributario del mes anterior por retenciones en la fuente de IVA', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 240],
            ['line_code' => '609', 'section' => 'resumen', 'label' => 'Retenciones en la fuente de IVA del período', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 250],
            ['line_code' => '615', 'section' => 'resumen', 'label' => 'Saldo de crédito tributario para el próximo período por adquisiciones e importaciones', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 260],
            ['line_code' => '617', 'section' => 'resumen', 'label' => 'Saldo de crédito tributario para el próximo período por retenciones en la fuente de IVA', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 270],
        ];

        return array_map(
            fn (array $row): array => self::withMetadata(
                $row + [
                    'form_code' => '104',
                    'valid_from' => '2024-04-01',
                    'valid_to' => null,
                ],
                self::SOURCE_FORM_104,
                '2024-04-01',
            ),
            $rows,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function personalExpenseCaps(): array
    {
        $basketCents = 82_180;
        $basketsByDependents = [0 => 7, 1 => 9, 2 => 11, 3 => 14, 4 => 17, 5 => 20];

        $rows = [];

        foreach ($basketsByDependents as $dependents => $baskets) {
            $rows[] = self::withMetadata([
                'fiscal_year' => 2026,
                'category_code' => 'global',
                'dependents' => $dependents,
                'cap_cents' => $baskets * $basketCents,
                'baskets' => $baskets,
                'basic_food_basket_cents' => $basketCents,
                'valid_from' => '2026-01-01',
                'valid_to' => '2026-12-31',
            ], self::SOURCE_CAPS_2026, '2026-01-01');
        }

        $rows[] = self::withMetadata([
            'fiscal_year' => 2026,
            'category_code' => 'catastrophic_illness',
            'dependents' => 0,
            'cap_cents' => 100 * $basketCents,
            'baskets' => 100,
            'basic_food_basket_cents' => $basketCents,
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
        ], self::SOURCE_CAPS_2026, '2026-01-01');

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function withMetadata(array $row, string $source, string $publishedAt): array
    {
        $row['source'] = $source;
        $row['published_at'] = $publishedAt;
        $row['checksum'] = hash('sha256', (string) json_encode($row, JSON_THROW_ON_ERROR));

        return $row;
    }
}
