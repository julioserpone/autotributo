<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Data;

/**
 * Borrador estructural del Formulario 104 (F4-09).
 *
 * El borrador es **de solo lectura derivada**: no persiste nada, no reemplaza
 * a la declaración real de F5-02 ni cierra el período. Sirve para ver, con los
 * comprobantes ya importados, qué cifras alimentaría cada casillero y desde
 * dónde sale.
 *
 * Los casilleros marcados como `requires_signoff` dependen de la convención
 * que firme el contador (`docs/fiscal-signoff.md`); el cálculo existe y es
 * reproducible, pero no se presenta como definitivo. Los que tienen
 * `unavailable` no se calculan todavía porque la fuente de datos no está en
 * `period_aggregates` (créditos de períodos anteriores o no clasificados).
 *
 * @see docs/02-implementation-plan.md § F4-09
 * @see docs/fiscal-signoff.md
 */
final readonly class Form104Draft
{
    public const STATUS_DRAFT = 'draft';

    public const FORM_CODE = '104';

    /**
     * @param  array{public_id: string, legal_name: string, ruc: string}  $company
     * @param  array{
     *     form_code: string,
     *     resolution: string,
     *     year: int,
     *     month: int,
     *     from: string,
     *     to: string,
     *     status: string|null,
     *     locked_at: string|null,
     *     computed_at: string|null,
     *     has_period: bool,
     * }  $period
     * @param  array{
     *     draft: bool,
     *     editable: bool,
     *     source: string,
     *     requires_signoff: bool,
     *     notice: string,
     * }  $status
     * @param  list<array<string, mixed>>  $lines
     * @param  array{
     *     sales_zero: int,
     *     sales_general: int,
     *     proportionality_factor: string|null,
     *     proportionality_complement: string|null,
     *     purchases_common_tax_to_credit: int|null,
     * }  $proportionality
     * @param  list<array{year: int, month: int, status: string|null, complete: bool}>  $previous_periods
     * @param  list<array{code: string, label: string, severity: string, total: int}>  $findings
     * @param  array{
     *     form_code: string,
     *     resolution: string,
     *     sections: list<array{key: string, title: string}>,
     * }  $official_form
     */
    public function __construct(
        public array $company,
        public array $period,
        public array $status,
        public array $lines,
        public array $proportionality,
        public array $previous_periods,
        public array $findings,
        public array $official_form,
    ) {}
}
