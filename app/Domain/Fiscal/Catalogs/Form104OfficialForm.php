<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Catalogs;

/**
 * Estructura del formulario oficial del 104 (Resolución NAC-DGERCGC16-00000210).
 *
 * Los casilleros del SRI se identifican por un **campo** numérico (columna
 * `campo` del XLS) y el sistema los identifica por `line_code`. Para el
 * formulario 104 ambos coinciden, pero se mantiene la columna explícita para
 * no depender de esa coincidencia al generar el archivo de importación.
 *
 * Cada casillero oficial declara `line_code`; cuando es `null`, el formulario
 * se llena a mano (datos que no provienen de comprobantes: intereses, multas,
 * notas de crédito del SRI o porcentajes legales).
 */
final class Form104OfficialForm
{
    public const FORM_CODE = '104';

    public const RESOLUTION = 'NAC-DGERCGC16-00000210';

    /**
     * Secciones del formulario en el orden del XLS oficial.
     *
     * @return list<array{
     *     key: string,
     *     title: string,
     *     fields: list<array{field: string, line_code: string|null, label: string, data_type: string}>
     * }>
     */
    public static function sections(): array
    {
        return [
            [
                'key' => 'ventas',
                'title' => 'Resumen de ventas y otras operaciones del período',
                'fields' => [
                    ['field' => '401', 'line_code' => '401', 'label' => 'Ventas locales (excluye activos fijos) gravadas tarifa diferente de cero', 'data_type' => 'amount'],
                    ['field' => '402', 'line_code' => '402', 'label' => 'Ventas de activos fijos gravadas tarifa diferente de cero', 'data_type' => 'amount'],
                    ['field' => '403', 'line_code' => '403', 'label' => 'Ventas locales gravadas tarifa 0% que no dan derecho a crédito tributario', 'data_type' => 'amount'],
                    ['field' => '404', 'line_code' => '404', 'label' => 'Ventas de activos fijos gravadas tarifa 0% que no dan derecho a crédito tributario', 'data_type' => 'amount'],
                    ['field' => '405', 'line_code' => '405', 'label' => 'Ventas locales gravadas tarifa 0% que dan derecho a crédito tributario', 'data_type' => 'amount'],
                    ['field' => '406', 'line_code' => '406', 'label' => 'Ventas de activos fijos gravadas tarifa 0% que dan derecho a crédito tributario', 'data_type' => 'amount'],
                    ['field' => '407', 'line_code' => '407', 'label' => 'Exportaciones de bienes', 'data_type' => 'amount'],
                    ['field' => '408', 'line_code' => '408', 'label' => 'Exportaciones de servicios', 'data_type' => 'amount'],
                    ['field' => '409', 'line_code' => '409', 'label' => 'Total ventas y otras operaciones (401+402+403+404+405+406+407+408)', 'data_type' => 'amount'],
                    ['field' => '411', 'line_code' => '411', 'label' => 'Ventas locales netas gravadas tarifa diferente de cero', 'data_type' => 'amount'],
                    ['field' => '412', 'line_code' => '412', 'label' => 'Ventas netas de activos fijos gravadas tarifa diferente de cero', 'data_type' => 'amount'],
                    ['field' => '415', 'line_code' => '415', 'label' => 'Ventas locales netas gravadas tarifa 0% que dan derecho a crédito tributario', 'data_type' => 'amount'],
                    ['field' => '416', 'line_code' => '416', 'label' => 'Ventas netas de activos fijos gravadas tarifa 0% que dan derecho a crédito tributario', 'data_type' => 'amount'],
                    ['field' => '417', 'line_code' => '417', 'label' => 'Exportaciones de bienes (valor neto)', 'data_type' => 'amount'],
                    ['field' => '418', 'line_code' => '418', 'label' => 'Exportaciones de servicios (valor neto)', 'data_type' => 'amount'],
                    ['field' => '419', 'line_code' => '419', 'label' => 'Total ventas y otras operaciones (valor neto)', 'data_type' => 'amount'],
                    ['field' => '421', 'line_code' => '421', 'label' => 'IVA generado en ventas locales gravadas tarifa diferente de cero', 'data_type' => 'amount'],
                    ['field' => '422', 'line_code' => '422', 'label' => 'IVA generado en ventas de activos fijos gravadas tarifa diferente de cero', 'data_type' => 'amount'],
                    ['field' => '423', 'line_code' => '423', 'label' => 'IVA generado en la diferencia entre ventas y notas de crédito con distinta tarifa', 'data_type' => 'amount'],
                    ['field' => '429', 'line_code' => '429', 'label' => 'Total impuesto generado (421+422+423)', 'data_type' => 'amount'],
                    ['field' => '431', 'line_code' => '431', 'label' => 'Transferencias no objeto o exentas de IVA', 'data_type' => 'amount'],
                    ['field' => '441', 'line_code' => '441', 'label' => 'Transferencias no objeto o exentas de IVA (valor neto)', 'data_type' => 'amount'],
                ],
            ],
            [
                'key' => 'liquidacion',
                'title' => 'Liquidación del IVA en el mes',
                'fields' => [
                    ['field' => '480', 'line_code' => '480', 'label' => 'Transferencias gravadas tarifa diferente de cero a contado este mes', 'data_type' => 'amount'],
                    ['field' => '481', 'line_code' => '481', 'label' => 'Transferencias gravadas tarifa diferente de cero a crédito este mes', 'data_type' => 'amount'],
                    ['field' => '482', 'line_code' => '482', 'label' => 'Total impuesto generado (trasládese el campo 429)', 'data_type' => 'amount'],
                    ['field' => '483', 'line_code' => '483', 'label' => 'Impuesto a liquidar del mes anterior (campo 485 del período anterior)', 'data_type' => 'amount'],
                    ['field' => '484', 'line_code' => '484', 'label' => 'Impuesto a liquidar en este mes (mínimo campo 480 × tarifa IVA)', 'data_type' => 'amount'],
                    ['field' => '485', 'line_code' => '485', 'label' => 'Impuesto a liquidar en el próximo mes (482 − 484)', 'data_type' => 'amount'],
                    ['field' => '499', 'line_code' => '499', 'label' => 'Total impuesto a liquidar en este mes (483 + 484)', 'data_type' => 'amount'],
                ],
            ],
            [
                'key' => 'compras',
                'title' => 'Resumen de adquisiciones y pagos',
                'fields' => [
                    ['field' => '500', 'line_code' => '500', 'label' => 'Adquisiciones y pagos (excluye activos fijos) gravados tarifa diferente de cero con derecho a crédito', 'data_type' => 'amount'],
                    ['field' => '501', 'line_code' => '501', 'label' => 'Adquisiciones locales de activos fijos gravados tarifa diferente de cero con derecho a crédito', 'data_type' => 'amount'],
                    ['field' => '502', 'line_code' => '502', 'label' => 'Otras adquisiciones y pagos gravados tarifa diferente de cero sin derecho a crédito', 'data_type' => 'amount'],
                    ['field' => '506', 'line_code' => '506', 'label' => 'Importaciones de bienes (incluye activos fijos) gravados tarifa 0%', 'data_type' => 'amount'],
                    ['field' => '507', 'line_code' => '507', 'label' => 'Adquisiciones y pagos (incluye activos fijos) gravados tarifa 0%', 'data_type' => 'amount'],
                    ['field' => '509', 'line_code' => '509', 'label' => 'Total adquisiciones y pagos (500+501+502+506+507)', 'data_type' => 'amount'],
                    ['field' => '510', 'line_code' => '510', 'label' => 'Adquisiciones y pagos netos gravados tarifa diferente de cero con derecho a crédito', 'data_type' => 'amount'],
                    ['field' => '511', 'line_code' => '511', 'label' => 'Adquisiciones netas de activos fijos gravadas tarifa diferente de cero con derecho a crédito', 'data_type' => 'amount'],
                    ['field' => '517', 'line_code' => '517', 'label' => 'Adquisiciones y pagos netos gravados tarifa 0%', 'data_type' => 'amount'],
                    ['field' => '519', 'line_code' => '519', 'label' => 'Total adquisiciones y pagos (valor neto)', 'data_type' => 'amount'],
                    ['field' => '520', 'line_code' => '520', 'label' => 'IVA generado en adquisiciones y pagos gravados tarifa diferente de cero con derecho a crédito', 'data_type' => 'amount'],
                    ['field' => '521', 'line_code' => '521', 'label' => 'IVA generado en adquisiciones locales de activos fijos gravados tarifa diferente de cero', 'data_type' => 'amount'],
                    ['field' => '522', 'line_code' => '522', 'label' => 'IVA generado en otras adquisiciones gravadas sin derecho a crédito', 'data_type' => 'amount'],
                    ['field' => '526', 'line_code' => '526', 'label' => 'IVA generado en la diferencia entre adquisiciones y notas de crédito con distinta tarifa', 'data_type' => 'amount'],
                    ['field' => '529', 'line_code' => '529', 'label' => 'Total IVA generado en adquisiciones y pagos', 'data_type' => 'amount'],
                    ['field' => '531', 'line_code' => '531', 'label' => 'Adquisiciones no objeto de IVA', 'data_type' => 'amount'],
                    ['field' => '532', 'line_code' => '532', 'label' => 'Adquisiciones exentas del pago de IVA', 'data_type' => 'amount'],
                    ['field' => '563', 'line_code' => '563', 'label' => 'Factor de proporcionalidad para crédito tributario ((411+412+415+416+417+418) / 419)', 'data_type' => 'rate'],
                    ['field' => '564', 'line_code' => '564', 'label' => 'Crédito tributario aplicable en este período (520+521+523+524+525+526) × 563', 'data_type' => 'amount'],
                ],
            ],
            [
                'key' => 'resumen',
                'title' => 'Resumen impositivo (agente de percepción del IVA)',
                'fields' => [
                    ['field' => '601', 'line_code' => '601', 'label' => 'Impuesto causado (si 499 − 564 es mayor que cero)', 'data_type' => 'amount'],
                    ['field' => '602', 'line_code' => '602', 'label' => 'Crédito tributario aplicable en este período (si 499 − 564 es menor que cero)', 'data_type' => 'amount'],
                    ['field' => '603', 'line_code' => '603', 'label' => 'Compensación de IVA por ventas efectuadas en su totalidad con medio electrónico', 'data_type' => 'amount'],
                    ['field' => '604', 'line_code' => '604', 'label' => 'Compensación de IVA por ventas efectuadas en zonas afectadas (Ley de Solidaridad)', 'data_type' => 'amount'],
                    ['field' => '605', 'line_code' => '605', 'label' => 'Saldo de crédito tributario del mes anterior por adquisiciones e importaciones (campo 615 anterior)', 'data_type' => 'amount'],
                    ['field' => '606', 'line_code' => '606', 'label' => 'Saldo de crédito tributario del mes anterior por retenciones en la fuente de IVA (campo 617 anterior)', 'data_type' => 'amount'],
                    ['field' => '607', 'line_code' => '607', 'label' => 'Saldo de crédito tributario del mes anterior por compensación con medio electrónico (campo 618 anterior)', 'data_type' => 'amount'],
                    ['field' => '608', 'line_code' => '608', 'label' => 'Saldo de crédito tributario del mes anterior por zonas afectadas (campo 619 anterior)', 'data_type' => 'amount'],
                    ['field' => '609', 'line_code' => '609', 'label' => 'Retenciones en la fuente de IVA que le han sido efectuadas en este período', 'data_type' => 'amount'],
                    ['field' => '610', 'line_code' => '610', 'label' => 'Ajuste por IVA devuelto o descontado por adquisiciones con medio electrónico', 'data_type' => 'amount'],
                    ['field' => '611', 'line_code' => '611', 'label' => 'Ajuste por IVA devuelto o descontado en zonas afectadas (Ley de Solidaridad)', 'data_type' => 'amount'],
                    ['field' => '612', 'line_code' => '612', 'label' => 'Ajuste por IVA devuelto y rechazado imputable al crédito tributario (devoluciones)', 'data_type' => 'amount'],
                    ['field' => '613', 'line_code' => '613', 'label' => 'Ajuste por IVA devuelto y rechazado imputable al crédito tributario (retenciones)', 'data_type' => 'amount'],
                    ['field' => '614', 'line_code' => '614', 'label' => 'Ajuste por IVA devuelto por otras instituciones del sector público', 'data_type' => 'amount'],
                    ['field' => '615', 'line_code' => '615', 'label' => 'Saldo de crédito tributario para el próximo mes por adquisiciones e importaciones', 'data_type' => 'amount'],
                    ['field' => '617', 'line_code' => '617', 'label' => 'Saldo de crédito tributario para el próximo mes por retenciones en la fuente de IVA', 'data_type' => 'amount'],
                    ['field' => '618', 'line_code' => '618', 'label' => 'Saldo de crédito tributario para el próximo mes por compensación con medio electrónico', 'data_type' => 'amount'],
                    ['field' => '619', 'line_code' => '619', 'label' => 'Saldo de crédito tributario para el próximo mes por zonas afectadas (Ley de Solidaridad)', 'data_type' => 'amount'],
                    ['field' => '620', 'line_code' => '620', 'label' => 'Subtotal a pagar (601 − 602 … 609 + 610 … 614)', 'data_type' => 'amount'],
                    ['field' => '621', 'line_code' => '621', 'label' => 'IVA presuntivo de salas de juego y otros juegos de azar (ejercicios anteriores a 2013)', 'data_type' => 'amount'],
                    ['field' => '699', 'line_code' => '699', 'label' => 'Total impuesto a pagar por percepción (620 + 621)', 'data_type' => 'amount'],
                ],
            ],
            [
                'key' => 'retencion',
                'title' => 'Agente de retención del IVA',
                'fields' => [
                    ['field' => '721', 'line_code' => '721', 'label' => 'Retención del 10%', 'data_type' => 'amount'],
                    ['field' => '723', 'line_code' => '723', 'label' => 'Retención del 20%', 'data_type' => 'amount'],
                    ['field' => '725', 'line_code' => '725', 'label' => 'Retención del 30%', 'data_type' => 'amount'],
                    ['field' => '727', 'line_code' => '727', 'label' => 'Retención del 50%', 'data_type' => 'amount'],
                    ['field' => '729', 'line_code' => '729', 'label' => 'Retención del 70%', 'data_type' => 'amount'],
                    ['field' => '731', 'line_code' => '731', 'label' => 'Retención del 100%', 'data_type' => 'amount'],
                    ['field' => '799', 'line_code' => '799', 'label' => 'Total impuesto retenido (721+723+725+727+729+731)', 'data_type' => 'amount'],
                    ['field' => '800', 'line_code' => '800', 'label' => 'Devolución provisional de IVA mediante compensación con retenciones efectuadas', 'data_type' => 'amount'],
                    ['field' => '801', 'line_code' => '801', 'label' => 'Total impuesto a pagar por retención (799 − 800)', 'data_type' => 'amount'],
                ],
            ],
            [
                'key' => 'pago',
                'title' => 'Total consolidado y pago',
                'fields' => [
                    ['field' => '859', 'line_code' => '859', 'label' => 'Total consolidado de impuesto al valor agregado (699 + 801)', 'data_type' => 'amount'],
                    ['field' => '890', 'line_code' => null, 'label' => 'Pago previo', 'data_type' => 'amount'],
                    ['field' => '897', 'line_code' => null, 'label' => 'Interés (imputación al pago en declaraciones sustitutivas)', 'data_type' => 'amount'],
                    ['field' => '898', 'line_code' => null, 'label' => 'Impuesto (imputación al pago en declaraciones sustitutivas)', 'data_type' => 'amount'],
                    ['field' => '899', 'line_code' => null, 'label' => 'Multa (imputación al pago en declaraciones sustitutivas)', 'data_type' => 'amount'],
                    ['field' => '902', 'line_code' => '902', 'label' => 'Total impuesto a pagar (859 − 898)', 'data_type' => 'amount'],
                    ['field' => '903', 'line_code' => null, 'label' => 'Interés por mora', 'data_type' => 'amount'],
                    ['field' => '904', 'line_code' => null, 'label' => 'Multa', 'data_type' => 'amount'],
                    ['field' => '907', 'line_code' => null, 'label' => 'Total pagado mediante notas de crédito', 'data_type' => 'amount'],
                    ['field' => '999', 'line_code' => '999', 'label' => 'Total pagado', 'data_type' => 'amount'],
                ],
            ],
        ];
    }
}
