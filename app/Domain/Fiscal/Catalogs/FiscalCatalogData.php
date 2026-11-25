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
     * Casilleros del Formulario 104 (Resolución NAC-DGERCGC16-00000210).
     *
     * Corresponde a las filas del formulario oficial en PDF/XLS: 401-431 y
     * 480-499 para ventas; 500-563/564 para adquisiciones; 601-620/699 para el
     * resumen impositivo; 721-799/801 para agente de retención y 859/902-999
     * para el pago. Los casilleros que se suman en el formulario oficial se
     * guardan solo si tienen valor propio: los subtotales se calculan.
     *
     * @return list<array<string, mixed>>
     */
    public function formLines(): array
    {
        $rows = [
            // Resumen de ventas y otras operaciones del período.
            ['line_code' => '401', 'section' => 'ventas', 'label' => 'Ventas locales (excluye activos fijos) gravadas tarifa diferente de cero', 'data_type' => 'amount', 'is_required' => true, 'display_order' => 10],
            ['line_code' => '402', 'section' => 'ventas', 'label' => 'Ventas de activos fijos gravadas tarifa diferente de cero', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 20],
            ['line_code' => '403', 'section' => 'ventas', 'label' => 'Ventas locales gravadas tarifa 0% que no dan derecho a crédito tributario', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 30],
            ['line_code' => '404', 'section' => 'ventas', 'label' => 'Ventas de activos fijos gravadas tarifa 0% que no dan derecho a crédito tributario', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 40],
            ['line_code' => '405', 'section' => 'ventas', 'label' => 'Ventas locales gravadas tarifa 0% que dan derecho a crédito tributario', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 50],
            ['line_code' => '406', 'section' => 'ventas', 'label' => 'Ventas de activos fijos gravadas tarifa 0% que dan derecho a crédito tributario', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 60],
            ['line_code' => '407', 'section' => 'ventas', 'label' => 'Exportaciones de bienes', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 70],
            ['line_code' => '408', 'section' => 'ventas', 'label' => 'Exportaciones de servicios', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 80],
            ['line_code' => '409', 'section' => 'ventas', 'label' => 'Total ventas y otras operaciones (valor bruto)', 'data_type' => 'amount', 'is_required' => true, 'display_order' => 90],
            ['line_code' => '411', 'section' => 'ventas', 'label' => 'Ventas locales netas gravadas tarifa diferente de cero', 'data_type' => 'amount', 'is_required' => true, 'display_order' => 100],
            ['line_code' => '412', 'section' => 'ventas', 'label' => 'Ventas netas de activos fijos gravadas tarifa diferente de cero', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 110],
            ['line_code' => '415', 'section' => 'ventas', 'label' => 'Ventas locales netas gravadas tarifa 0% que dan derecho a crédito tributario', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 120],
            ['line_code' => '416', 'section' => 'ventas', 'label' => 'Ventas netas de activos fijos gravadas tarifa 0% que dan derecho a crédito tributario', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 130],
            ['line_code' => '417', 'section' => 'ventas', 'label' => 'Exportaciones de bienes (valor neto)', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 140],
            ['line_code' => '418', 'section' => 'ventas', 'label' => 'Exportaciones de servicios (valor neto)', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 150],
            ['line_code' => '419', 'section' => 'ventas', 'label' => 'Total ventas y otras operaciones (valor neto)', 'data_type' => 'amount', 'is_required' => true, 'display_order' => 160],
            ['line_code' => '421', 'section' => 'ventas', 'label' => 'IVA generado en ventas locales gravadas tarifa diferente de cero', 'data_type' => 'amount', 'is_required' => true, 'display_order' => 170],
            ['line_code' => '422', 'section' => 'ventas', 'label' => 'IVA generado en ventas de activos fijos gravadas tarifa diferente de cero', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 180],
            ['line_code' => '423', 'section' => 'ventas', 'label' => 'IVA generado en la diferencia entre ventas y notas de crédito con distinta tarifa', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 190],
            ['line_code' => '429', 'section' => 'ventas', 'label' => 'Total impuesto generado', 'data_type' => 'amount', 'is_required' => true, 'display_order' => 200],
            ['line_code' => '431', 'section' => 'ventas', 'label' => 'Transferencias no objeto o exentas de IVA', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 210],
            ['line_code' => '441', 'section' => 'ventas', 'label' => 'Transferencias no objeto o exentas de IVA (valor neto)', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 220],

            // Liquidación del IVA en el mes.
            ['line_code' => '480', 'section' => 'liquidacion', 'label' => 'Transferencias gravadas tarifa diferente de cero a contado este mes', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 300],
            ['line_code' => '481', 'section' => 'liquidacion', 'label' => 'Transferencias gravadas tarifa diferente de cero a crédito este mes', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 310],
            ['line_code' => '482', 'section' => 'liquidacion', 'label' => 'Total impuesto generado (trasládese el campo 429)', 'data_type' => 'amount', 'is_required' => true, 'display_order' => 320],
            ['line_code' => '483', 'section' => 'liquidacion', 'label' => 'Impuesto a liquidar del mes anterior (campo 485 del período anterior)', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 330],
            ['line_code' => '484', 'section' => 'liquidacion', 'label' => 'Impuesto a liquidar en este mes (mínimo campo 480 × tarifa IVA)', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 340],
            ['line_code' => '485', 'section' => 'liquidacion', 'label' => 'Impuesto a liquidar en el próximo mes (482 − 484)', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 350],
            ['line_code' => '499', 'section' => 'liquidacion', 'label' => 'Total impuesto a liquidar en este mes (483 + 484)', 'data_type' => 'amount', 'is_required' => true, 'display_order' => 360],

            // Resumen de adquisiciones y pagos.
            ['line_code' => '500', 'section' => 'compras', 'label' => 'Adquisiciones y pagos (excluye activos fijos) gravados tarifa diferente de cero con derecho a crédito', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 400],
            ['line_code' => '501', 'section' => 'compras', 'label' => 'Adquisiciones locales de activos fijos gravados tarifa diferente de cero con derecho a crédito', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 410],
            ['line_code' => '502', 'section' => 'compras', 'label' => 'Otras adquisiciones y pagos gravados tarifa diferente de cero sin derecho a crédito', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 420],
            ['line_code' => '506', 'section' => 'compras', 'label' => 'Importaciones de bienes (incluye activos fijos) gravados tarifa 0%', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 430],
            ['line_code' => '507', 'section' => 'compras', 'label' => 'Adquisiciones y pagos (incluye activos fijos) gravados tarifa 0%', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 440],
            ['line_code' => '509', 'section' => 'compras', 'label' => 'Total adquisiciones y pagos (valor bruto)', 'data_type' => 'amount', 'is_required' => true, 'display_order' => 450],
            ['line_code' => '510', 'section' => 'compras', 'label' => 'Adquisiciones y pagos netos gravados tarifa diferente de cero con derecho a crédito', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 460],
            ['line_code' => '511', 'section' => 'compras', 'label' => 'Adquisiciones netas de activos fijos gravadas tarifa diferente de cero con derecho a crédito', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 470],
            ['line_code' => '517', 'section' => 'compras', 'label' => 'Adquisiciones y pagos netos gravados tarifa 0%', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 480],
            ['line_code' => '519', 'section' => 'compras', 'label' => 'Total adquisiciones y pagos (valor neto)', 'data_type' => 'amount', 'is_required' => true, 'display_order' => 490],
            ['line_code' => '520', 'section' => 'compras', 'label' => 'IVA generado en adquisiciones y pagos gravados tarifa diferente de cero con derecho a crédito', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 500],
            ['line_code' => '521', 'section' => 'compras', 'label' => 'IVA generado en adquisiciones locales de activos fijos gravados tarifa diferente de cero', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 510],
            ['line_code' => '522', 'section' => 'compras', 'label' => 'IVA generado en otras adquisiciones gravadas sin derecho a crédito', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 520],
            ['line_code' => '526', 'section' => 'compras', 'label' => 'IVA generado en la diferencia entre adquisiciones y notas de crédito con distinta tarifa', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 530],
            ['line_code' => '529', 'section' => 'compras', 'label' => 'Total IVA generado en adquisiciones y pagos', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 540],
            ['line_code' => '531', 'section' => 'compras', 'label' => 'Adquisiciones no objeto de IVA', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 550],
            ['line_code' => '532', 'section' => 'compras', 'label' => 'Adquisiciones exentas del pago de IVA', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 560],
            ['line_code' => '563', 'section' => 'compras', 'label' => 'Factor de proporcionalidad para crédito tributario ((411+412+415+416+417+418) / 419)', 'data_type' => 'rate', 'is_required' => false, 'display_order' => 570],
            ['line_code' => '564', 'section' => 'compras', 'label' => 'Crédito tributario aplicable en este período (520+521+523+524+525+526) × 563', 'data_type' => 'amount', 'is_required' => true, 'display_order' => 580],

            // Resumen impositivo (agente de percepción del IVA).
            ['line_code' => '601', 'section' => 'resumen', 'label' => 'Impuesto causado (si 499 − 564 es mayor que cero)', 'data_type' => 'amount', 'is_required' => true, 'display_order' => 600],
            ['line_code' => '602', 'section' => 'resumen', 'label' => 'Crédito tributario aplicable en este período (si 499 − 564 es menor que cero)', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 610],
            ['line_code' => '603', 'section' => 'resumen', 'label' => 'Compensación de IVA por ventas efectuadas en su totalidad con medio electrónico', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 620],
            ['line_code' => '604', 'section' => 'resumen', 'label' => 'Compensación de IVA por ventas efectuadas en zonas afectadas (Ley de Solidaridad)', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 630],
            ['line_code' => '605', 'section' => 'resumen', 'label' => 'Saldo de crédito tributario del mes anterior por adquisiciones e importaciones (campo 615 anterior)', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 640],
            ['line_code' => '606', 'section' => 'resumen', 'label' => 'Saldo de crédito tributario del mes anterior por retenciones en la fuente de IVA (campo 617 anterior)', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 650],
            ['line_code' => '607', 'section' => 'resumen', 'label' => 'Saldo de crédito tributario del mes anterior por compensación con medio electrónico (campo 618 anterior)', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 660],
            ['line_code' => '608', 'section' => 'resumen', 'label' => 'Saldo de crédito tributario del mes anterior por zonas afectadas (campo 619 anterior)', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 670],
            ['line_code' => '609', 'section' => 'resumen', 'label' => 'Retenciones en la fuente de IVA que le han sido efectuadas en este período', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 680],
            ['line_code' => '610', 'section' => 'resumen', 'label' => 'Ajuste por IVA devuelto o descontado por adquisiciones con medio electrónico', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 690],
            ['line_code' => '611', 'section' => 'resumen', 'label' => 'Ajuste por IVA devuelto o descontado en zonas afectadas (Ley de Solidaridad)', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 700],
            ['line_code' => '612', 'section' => 'resumen', 'label' => 'Ajuste por IVA devuelto y rechazado imputable al crédito tributario (devoluciones)', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 710],
            ['line_code' => '613', 'section' => 'resumen', 'label' => 'Ajuste por IVA devuelto y rechazado imputable al crédito tributario (retenciones)', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 720],
            ['line_code' => '614', 'section' => 'resumen', 'label' => 'Ajuste por IVA devuelto por otras instituciones del sector público', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 730],
            ['line_code' => '615', 'section' => 'resumen', 'label' => 'Saldo de crédito tributario para el próximo mes por adquisiciones e importaciones', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 740],
            ['line_code' => '617', 'section' => 'resumen', 'label' => 'Saldo de crédito tributario para el próximo mes por retenciones en la fuente de IVA', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 750],
            ['line_code' => '618', 'section' => 'resumen', 'label' => 'Saldo de crédito tributario para el próximo mes por compensación con medio electrónico', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 760],
            ['line_code' => '619', 'section' => 'resumen', 'label' => 'Saldo de crédito tributario para el próximo mes por zonas afectadas (Ley de Solidaridad)', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 770],
            ['line_code' => '620', 'section' => 'resumen', 'label' => 'Subtotal a pagar (601 − 602 … 609 + 610 … 614)', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 780],
            ['line_code' => '621', 'section' => 'resumen', 'label' => 'IVA presuntivo de salas de juego y otros juegos de azar (ejercicios anteriores a 2013)', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 790],
            ['line_code' => '699', 'section' => 'resumen', 'label' => 'Total impuesto a pagar por percepción (620 + 621)', 'data_type' => 'amount', 'is_required' => true, 'display_order' => 800],

            // Agente de retención del IVA.
            ['line_code' => '721', 'section' => 'retencion', 'label' => 'Retención del 10%', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 900],
            ['line_code' => '723', 'section' => 'retencion', 'label' => 'Retención del 20%', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 910],
            ['line_code' => '725', 'section' => 'retencion', 'label' => 'Retención del 30%', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 920],
            ['line_code' => '727', 'section' => 'retencion', 'label' => 'Retención del 50%', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 930],
            ['line_code' => '729', 'section' => 'retencion', 'label' => 'Retención del 70%', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 940],
            ['line_code' => '731', 'section' => 'retencion', 'label' => 'Retención del 100%', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 950],
            ['line_code' => '799', 'section' => 'retencion', 'label' => 'Total impuesto retenido (721+723+725+727+729+731)', 'data_type' => 'amount', 'is_required' => true, 'display_order' => 960],
            ['line_code' => '800', 'section' => 'retencion', 'label' => 'Devolución provisional de IVA mediante compensación con retenciones efectuadas', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 970],
            ['line_code' => '801', 'section' => 'retencion', 'label' => 'Total impuesto a pagar por retención (799 − 800)', 'data_type' => 'amount', 'is_required' => true, 'display_order' => 980],

            // Total consolidado y pago.
            ['line_code' => '859', 'section' => 'pago', 'label' => 'Total consolidado de impuesto al valor agregado (699 + 801)', 'data_type' => 'amount', 'is_required' => true, 'display_order' => 1000],
            ['line_code' => '890', 'section' => 'pago', 'label' => 'Pago previo', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 1010],
            ['line_code' => '897', 'section' => 'pago', 'label' => 'Interés (imputación al pago en declaraciones sustitutivas)', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 1020],
            ['line_code' => '898', 'section' => 'pago', 'label' => 'Impuesto (imputación al pago en declaraciones sustitutivas)', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 1030],
            ['line_code' => '899', 'section' => 'pago', 'label' => 'Multa (imputación al pago en declaraciones sustitutivas)', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 1040],
            ['line_code' => '902', 'section' => 'pago', 'label' => 'Total impuesto a pagar (859 − 898)', 'data_type' => 'amount', 'is_required' => true, 'display_order' => 1050],
            ['line_code' => '903', 'section' => 'pago', 'label' => 'Interés por mora', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 1060],
            ['line_code' => '904', 'section' => 'pago', 'label' => 'Multa', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 1070],
            ['line_code' => '907', 'section' => 'pago', 'label' => 'Total pagado mediante notas de crédito', 'data_type' => 'amount', 'is_required' => false, 'display_order' => 1080],
            ['line_code' => '999', 'section' => 'pago', 'label' => 'Total pagado', 'data_type' => 'amount', 'is_required' => true, 'display_order' => 1090],
        ];

        return array_map(
            fn (array $row): array => self::withMetadata(
                $row + [
                    'form_code' => '104',
                    'valid_from' => '2016-01-01',
                    'valid_to' => null,
                ],
                self::SOURCE_FORM_104,
                '2016-01-01',
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
