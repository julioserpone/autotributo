<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Actions;

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Catalogs\Form104OfficialForm;
use App\Domain\Fiscal\Data\Form104Draft;
use App\Domain\Fiscal\Enums\FiscalRiskCode;
use App\Domain\Fiscal\Enums\FiscalRiskStatus;
use App\Domain\Fiscal\Models\FiscalFinding;
use App\Domain\Fiscal\Models\FiscalPeriod;
use App\Domain\Fiscal\Models\FormLine;
use App\Domain\Fiscal\Models\PeriodAggregate;
use App\Domain\Fiscal\Services\Proportionality;
use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Domain\Shared\Values\Money;
use App\Domain\Sri\Enums\SriDocumentType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Construye el borrador del Formulario 104 con la estructura oficial (F4-09).
 *
 * El formulario se recorre **campo por campo** en el orden del XLS oficial
 * (`Form104OfficialForm`), no según los casilleros que hayan en el catálogo:
 * así una cifra del SRI nunca queda fuera de la vista por faltar en la tabla.
 *
 * Solo lectura: se recalcula desde `period_aggregates` y `form_lines` vigentes
 * al último día del período, sin escribir nada. Cada casillero declara de qué
 * agregados sale (`sources`) para hacer drill-down a la bandeja, si depende de
 * la firma del contador (`requires_signoff`) o si aún no tiene fuente
 * (`unavailable`). Un casillero sin fuente no se muestra como cero: un cero
 * silencioso en una declaración es una cifra falsa.
 *
 * El cierre del período, el arrastre de crédito y la declaración versionada
 * son de F5-02/F5-05; aquí no se crean ni modifican filas.
 */
final readonly class BuildForm104Draft
{
    private const IVA_TAX_CODE = '2';

    /** Códigos de porcentaje del SRI que corresponden a tarifa 0 %. */
    private const ZERO_PERCENTAGE_CODES = ['0'];

    private const PERCENTAGE_FIVE = '5';

    public function handle(Company $company, int $year, int $month): Form104Draft
    {
        $start = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $period = FiscalPeriod::query()
            ->where('company_id', $company->id)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        $aggregates = $this->aggregates($period);
        $periodFilter = [
            'date_from' => $start->toDateString(),
            'date_to' => $end->toDateString(),
        ];

        $values = $this->values($aggregates);

        $catalog = FormLine::query()
            ->validOn($end->toDateString())
            ->where('form_code', Form104Draft::FORM_CODE)
            ->get()
            ->keyBy('line_code');

        /** @var Collection<string, FormLine> $catalog */

        /** @var list<array<string, mixed>> $lines */
        $lines = [];

        foreach (Form104OfficialForm::sections() as $section) {
            foreach ($section['fields'] as $field) {
                $lines[] = $this->line($section['key'], $field, $catalog, $values, $periodFilter);
            }
        }

        return new Form104Draft(
            company: [
                'public_id' => $company->public_id,
                'legal_name' => $company->legal_name,
                'ruc' => $company->ruc,
            ],
            period: [
                'form_code' => Form104Draft::FORM_CODE,
                'resolution' => Form104OfficialForm::RESOLUTION,
                'year' => $year,
                'month' => $month,
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
                'status' => $period?->status,
                'locked_at' => $period?->locked_at?->toIso8601String(),
                'computed_at' => $period?->aggregates()->max('computed_at'),
                'has_period' => $period instanceof FiscalPeriod,
            ],
            status: [
                'draft' => true,
                'editable' => false,
                'source' => 'period_aggregates',
                'requires_signoff' => true,
                'notice' => 'Borrador derivado de los comprobantes importados. No es una declaración: el cierre, el arrastre de crédito y la presentación llegan en F5-02/F5-05 y requieren la firma del contador.',
            ],
            lines: array_values($lines),
            proportionality: $values['proportionality'],
            previous_periods: $this->previousPeriods($company, $year, $month),
            findings: $this->findings($company, $start, $end),
            official_form: [
                'form_code' => Form104Draft::FORM_CODE,
                'resolution' => Form104OfficialForm::RESOLUTION,
                'sections' => array_map(
                    static fn (array $section): array => [
                        'key' => $section['key'],
                        'title' => $section['title'],
                    ],
                    Form104OfficialForm::sections(),
                ),
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function aggregates(?FiscalPeriod $period): array
    {
        if (! $period instanceof FiscalPeriod) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = PeriodAggregate::query()
            ->where('fiscal_period_id', $period->id)
            ->get()
            ->map(static fn (PeriodAggregate $row): array => [
                'direction' => (string) $row->direction,
                'document_type' => (string) $row->document_type,
                'tax_code' => (string) $row->tax_code,
                'sri_percentage_code' => (string) $row->sri_percentage_code,
                'rate' => (string) $row->rate,
                'base_cents' => (int) $row->base_cents,
                'tax_cents' => (int) $row->tax_cents,
                'retention_cents' => (int) $row->retention_cents,
                'documents_count' => (int) $row->documents_count,
            ])
            ->values()
            ->all();

        return $rows;
    }

    /**
     * Cifras intermedias del formulario, calculadas una sola vez.
     *
     * @param  list<array<string, mixed>>  $aggregates
     * @return array<string, mixed>
     */
    private function values(array $aggregates): array
    {
        $sales = $this->iva($aggregates, 'sales');
        $purchases = $this->iva($aggregates, 'purchases');

        // Ventas gravadas al 15 %: 401 base e 421 IVA. La tarifa 5 % entra en
        // las mismas casillas porque el formulario no las separa.
        $gravadasBase = $sales['general']['base_cents'] + $sales['five']['base_cents'];
        $gravadasIva = $sales['general']['tax_cents'] + $sales['five']['tax_cents'];

        // Ventas 0 % con derecho a crédito: el formulario separa locales (405)
        // de activos fijos (406). El comprobante no distingue el activo fijo,
        // así que todo va a 405 y 406 queda en cero explícito.
        $zeroWithCredit = $sales['zero']['base_cents'];

        $comprasBase = $purchases['general']['base_cents'] + $purchases['five']['base_cents'];
        $comprasIva = $purchases['general']['tax_cents'] + $purchases['five']['tax_cents'];

        $salesGeneralBase = $sales['general']['base_cents'] + $sales['five']['base_cents'];
        $factor = ($zeroWithCredit + $salesGeneralBase) === 0
            ? null
            : Proportionality::factor(Money::cents($zeroWithCredit), Money::cents($salesGeneralBase));

        $credito = $factor === null
            ? null
            : Proportionality::apply(Money::cents($comprasIva), Proportionality::complement($factor))->toCents();

        return [
            'sales' => $sales,
            'purchases' => $purchases,
            'withholdings' => $this->withholdings($aggregates),
            'gravadas_base' => $gravadasBase,
            'gravadas_iva' => $gravadasIva,
            'zero_with_credit' => $zeroWithCredit,
            'compras_base' => $comprasBase,
            'compras_iva' => $comprasIva,
            'credito' => $credito,
            'proportionality' => [
                'sales_zero' => $zeroWithCredit,
                'sales_general' => $salesGeneralBase,
                'proportionality_factor' => $factor === null ? null : (string) $factor->toScale(Proportionality::SCALE),
                'proportionality_complement' => $factor === null
                    ? null
                    : (string) Proportionality::complement($factor)->toScale(Proportionality::SCALE),
                'purchases_common_tax_to_credit' => $credito,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  Collection<string, FormLine>  $catalog
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $periodFilter
     * @return array<string, mixed>
     */
    private function line(string $section, array $field, $catalog, array $values, array $periodFilter): array
    {
        /** @var FormLine|null $catalogLine */
        $catalogLine = $catalog->get($field['field']);

        $base = [
            'field' => $field['field'],
            'line_code' => $field['field'],
            'section' => $section,
            'label' => $field['label'],
            'data_type' => $field['data_type'],
            'is_required' => $catalogLine instanceof FormLine && $catalogLine->is_required,
            'value_cents' => null,
            'amount_cents' => null,
            'base_cents' => null,
            'rate' => null,
            'applies_to' => 'pending',
            'sources' => [],
            'requires_signoff' => false,
            'unavailable' => false,
            'manual' => $field['line_code'] === null,
            'note' => null,
        ];

        if ($field['line_code'] === null) {
            return $this->manual($base, 'Dato que no proviene de comprobantes (pago previo, intereses, multas o notas de crédito del SRI). Se completa en F5-02.');
        }

        return match ($field['field']) {
            // Ventas gravadas.
            '401', '402' => $this->amount($base, $values['gravadas_base'], 'ventas', $periodFilter, 'Las ventas gravadas al 15 % y al 5 % se registran juntas en el formulario oficial; aquí se informa el total.'),
            '411', '412' => $this->amount($base, $values['gravadas_base'], 'ventas', $periodFilter, 'Valor neto (después de notas de crédito). El borrador no ajusta NC todavía: F5-02 lo desglosa.'),
            '421', '422' => $this->amount($base, $values['gravadas_iva'], 'ventas', $periodFilter, null, requiresSignoff: true),
            '423' => $this->unavailable($base, 'Diferencia entre ventas y notas de crédito con distinta tarifa: requiere el ajuste de NC de F5-02.'),
            '429' => $this->amount($base, $values['gravadas_iva'], 'ventas', $periodFilter, 'Total impuesto generado: suma de 421, 422 y 423.'),
            '403', '404' => $this->unavailable($base, 'El comprobante no informa si la venta 0 % da derecho a crédito tributario; se completa en F5-02.'),
            '405', '415' => $this->amount($base, $values['zero_with_credit'], 'ventas', $periodFilter, 'Ventas 0 % con derecho a crédito. El borrador las ubica aquí porque el comprobante no distingue activos fijos.'),
            '406', '416' => $this->amount($base, 0, 'ventas', $periodFilter, 'Activos fijos 0 %: el borrador no separa activos fijos, por eso el valor es cero.'),
            '407', '417', '408', '418' => $this->amount($base, 0, 'ventas', $periodFilter, 'El comprobante no marca la exportación; si el contribuyente exporta, se ajusta en F5-02.'),
            '409' => $this->amount($base, $values['gravadas_base'] + $values['zero_with_credit'], 'ventas', $periodFilter, 'Total ventas y otras operaciones (valor bruto).'),
            '419' => $this->amount($base, $values['gravadas_base'] + $values['zero_with_credit'], 'ventas', $periodFilter, 'Total ventas y otras operaciones (valor neto).'),
            '431', '441' => $this->amount($base, 0, 'ventas', $periodFilter, 'Transferencias no objeto o exentas: no se distinguen en el comprobante, se ajustan en F5-02.'),

            // Liquidación del IVA en el mes.
            '480', '481' => $this->unavailable($base, 'El comprobante no distingue ventas a contado y a crédito; se completa en F5-02.'),
            '482' => $this->amount($base, $values['gravadas_iva'], 'ventas', $periodFilter, 'Total impuesto generado (campo 429).'),
            '483', '484', '485', '499' => $this->unavailable($base, 'La liquidación del IVA depende de la forma de pago y del arrastre del mes anterior (F5-02).'),

            // Adquisiciones.
            '500', '501', '502' => $this->amount($base, $values['compras_base'], 'compras', $periodFilter, 'Las adquisiciones gravadas al 15 % y al 5 % se registran juntas; el formulario oficial no separa el activo fijo en el borrador.'),
            '506', '507', '531', '532' => $this->unavailable($base, 'El comprobante no distingue importaciones, adquisiciones no objeto ni exentas; se ajusta en F5-02.'),
            '509', '519' => $this->amount($base, $values['compras_base'], 'compras', $periodFilter, 'Total adquisiciones y pagos.'),
            '510', '511' => $this->amount($base, $values['compras_base'], 'compras', $periodFilter, 'Valor neto de adquisiciones con derecho a crédito.'),
            '517' => $this->amount($base, $values['purchases']['zero']['base_cents'], 'compras', $periodFilter, 'Adquisiciones 0 %: no generan crédito tributario.'),
            '520', '521' => $this->amount($base, $values['compras_iva'], 'compras', $periodFilter, 'IVA generado en adquisiciones con derecho a crédito.'),
            '522' => $this->amount($base, 0, 'compras', $periodFilter, 'Otras adquisiciones sin derecho a crédito: el borrador no las separa.'),
            '526' => $this->unavailable($base, 'Diferencia entre adquisiciones y notas de crédito con distinta tarifa: F5-02.'),
            '529' => $this->amount($base, $values['compras_iva'], 'compras', $periodFilter, 'Total IVA generado en adquisiciones y pagos.'),
            '563' => $this->factor($base, $values),
            '564' => $this->amount($base, $values['credito'] ?? 0, 'compras', $periodFilter, 'Crédito tributario aplicable: IVA de compras por el factor de proporcionalidad. La convención exacta la firma el contador.', requiresSignoff: true),

            // Resumen impositivo.
            '601', '602', '620', '699' => $this->unavailable($base, 'El resumen impositivo depende de la liquidación del mes, los saldos del mes anterior y la firma del contador (F5-02).'),
            '603', '604', '607', '608', '610', '611', '612', '613', '614', '618', '619' => $this->unavailable($base, 'Compensaciones, ajustes y saldos del mes anterior: llegan con el ciclo de vida de la declaración (F5-02/F5-05).'),
            '605', '606' => $this->unavailable($base, 'El saldo de crédito del mes anterior llega con el ciclo de vida de la declaración (F5-02/F5-05).'),
            '609' => $this->amount($base, $values['withholdings']['iva'], 'compras', $periodFilter, 'Retenciones de IVA que terceros le practicaron (comprobantes de retención recibidos).'),
            '615', '617' => $this->unavailable($base, 'El saldo que pasa al próximo período se determina al cerrar la declaración (F5-02/F5-05).'),
            '621' => $this->unavailable($base, 'Aplica solo a ejercicios anteriores a 2013.'),

            // Agente de retención del IVA.
            '721', '723', '725', '727', '729', '731' => $this->retention($base, $field['field'], $values['withholdings']['by_rate'], $periodFilter),
            '799' => $this->amount($base, $values['withholdings']['iva'], 'compras', $periodFilter, 'Total impuesto retenido (comprobantes de retención emitidos).'),
            '800' => $this->unavailable($base, 'Devolución provisional de IVA: requiere la información de compensaciones (F5-02).'),
            '801' => $this->amount($base, $values['withholdings']['iva'], 'compras', $periodFilter, 'Total impuesto a pagar por retención (799 − 800).'),

            // Total consolidado y pago.
            '859' => $this->unavailable($base, 'Total consolidado: depende de 699 y 801, que se completan en F5-02.'),
            '902', '999' => $this->unavailable($base, 'El pago depende de la declaración cerrada (F5-02/F5-05).'),

            default => $this->unavailable($base, 'Casillero sin regla de cálculo en el borrador de F4-09.'),
        };
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, string>  $periodFilter
     * @return array<string, mixed>
     */
    private function amount(
        array $base,
        int $cents,
        string $direction,
        array $periodFilter,
        ?string $note,
        bool $requiresSignoff = false,
    ): array {
        return [
            ...$base,
            'value_cents' => $cents,
            'amount_cents' => $cents,
            'applies_to' => $direction,
            'sources' => [
                [
                    'type' => 'fiscal_documents',
                    'key' => $direction,
                    'label' => $direction === 'ventas'
                        ? 'Ver comprobantes de venta del período'
                        : 'Ver comprobantes de compra del período',
                    'filter' => [...$periodFilter, 'direction' => $direction],
                ],
            ],
            'requires_signoff' => $requiresSignoff,
            'note' => $note,
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function factor(array $base, array $values): array
    {
        $factor = $values['proportionality']['proportionality_factor'];

        return [
            ...$base,
            'value_cents' => null,
            'rate' => $factor,
            'applies_to' => 'compras',
            'requires_signoff' => true,
            'note' => $factor === null
                ? 'Sin ventas en el período no hay factor que aplicar.'
                : 'Factor de proporcionalidad (6 decimales, redondeo HALF_UP). El formulario lo informa como porcentaje.',
        ];
    }

    /**
     * Casillero de retención del IVA por tarifa (721 a 731).
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, int>  $byRate
     * @param  array<string, string>  $periodFilter
     * @return array<string, mixed>
     */
    private function retention(array $base, string $field, array $byRate, array $periodFilter): array
    {
        $cents = $byRate[$field] ?? 0;

        return [
            ...$this->amount(
                $base,
                $cents,
                'compras',
                $periodFilter,
                'Retenciones de IVA que la empresa practicó con esta tarifa (comprobantes de retención emitidos).',
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function unavailable(array $base, string $note): array
    {
        return [
            ...$base,
            'applies_to' => 'unavailable',
            'unavailable' => true,
            'note' => $note,
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function manual(array $base, string $note): array
    {
        return [
            ...$base,
            'applies_to' => 'manual',
            'unavailable' => true,
            'note' => $note,
        ];
    }

    /**
     * IVA por dirección y tarifa según `sri_percentage_code`.
     *
     * @param  list<array<string, mixed>>  $aggregates
     * @return array{
     *     zero: array{base_cents: int, tax_cents: int},
     *     five: array{base_cents: int, tax_cents: int},
     *     general: array{base_cents: int, tax_cents: int},
     *     total_tax_cents: int,
     * }
     */
    private function iva(array $aggregates, string $direction): array
    {
        $result = [
            'zero' => ['base_cents' => 0, 'tax_cents' => 0],
            'five' => ['base_cents' => 0, 'tax_cents' => 0],
            'general' => ['base_cents' => 0, 'tax_cents' => 0],
            'total_tax_cents' => 0,
        ];

        foreach ($aggregates as $row) {
            if ($row['tax_code'] !== self::IVA_TAX_CODE || $row['direction'] !== $direction) {
                continue;
            }

            $bucket = match (true) {
                in_array($row['sri_percentage_code'], self::ZERO_PERCENTAGE_CODES, true) => 'zero',
                $row['sri_percentage_code'] === self::PERCENTAGE_FIVE => 'five',
                default => 'general',
            };

            $result[$bucket]['base_cents'] += $row['base_cents'];
            $result[$bucket]['tax_cents'] += $row['tax_cents'];
            $result['total_tax_cents'] += $row['tax_cents'];
        }

        return $result;
    }

    /**
     * Retenciones practicadas por la empresa (comprobantes de retención
     * emitidos): IVA e IR, y el IVA desglosado por tarifa (721-731).
     *
     * @param  list<array<string, mixed>>  $aggregates
     * @return array{iva: int, ir: int, by_rate: array<string, int>}
     */
    private function withholdings(array $aggregates): array
    {
        $result = ['iva' => 0, 'ir' => 0, 'by_rate' => []];

        foreach ($aggregates as $row) {
            if ($row['document_type'] !== SriDocumentType::Withholding->value || $row['direction'] !== 'purchases') {
                continue;
            }

            $result['iva'] += (int) $row['retention_cents'];
            $code = (string) $row['tax_code'];

            // Solo las retenciones de IVA (7xx) tienen casillero en el 104.
            if (str_starts_with($code, '7')) {
                $result['by_rate'][$code] = ($result['by_rate'][$code] ?? 0) + (int) $row['retention_cents'];
            } else {
                $result['ir'] += (int) $row['retention_cents'];
            }
        }

        return $result;
    }

    /**
     * Períodos anteriores que podrían aportar crédito tributario.
     *
     * @return list<array{year: int, month: int, status: string|null, complete: bool}>
     */
    private function previousPeriods(Company $company, int $year, int $month): array
    {
        /** @var list<array{year: int, month: int, status: string|null, complete: bool}> $periods */
        $periods = FiscalPeriod::query()
            ->where('company_id', $company->id)
            ->where(static function ($query) use ($year, $month): void {
                $query->where('year', '<', $year)
                    ->orWhere(static function ($nested) use ($year, $month): void {
                        $nested->where('year', $year)->where('month', '<', $month);
                    });
            })
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->limit(3)
            ->get(['year', 'month', 'status', 'locked_at'])
            ->map(static fn (FiscalPeriod $period): array => [
                'year' => (int) $period->year,
                'month' => (int) $period->month,
                'status' => $period->status,
                'complete' => $period->locked_at !== null,
            ])
            ->values()
            ->all();

        return $periods;
    }

    /**
     * Hallazgos abiertos que bloquean el cierre de la declaración.
     *
     * @return list<array{code: string, label: string, severity: string, total: int}>
     */
    private function findings(Company $company, Carbon $start, Carbon $end): array
    {
        $blocking = [
            FiscalRiskCode::TotalsMismatch->value,
            FiscalRiskCode::InvalidRuc->value,
            FiscalRiskCode::GhostCompany->value,
            FiscalRiskCode::DuplicateSequential->value,
        ];

        $documentIds = FiscalDocument::query()
            ->where('company_id', $company->id)
            ->whereBetween('issue_date', [$start->toDateString(), $end->toDateString()])
            ->select('id');

        $rows = FiscalFinding::query()
            ->where('company_id', $company->id)
            ->where('status', FiscalRiskStatus::Open->value)
            ->whereIn('code', $blocking)
            ->where('source_type', 'fiscal_document')
            ->whereIn('source_id', $documentIds)
            ->selectRaw('code, severity, COUNT(*) AS aggregate')
            ->groupBy('code', 'severity')
            ->get();

        /** @var list<array{code: string, label: string, severity: string, total: int}> $list */
        $list = $rows->map(static function (FiscalFinding $row): array {
            $code = $row->code instanceof FiscalRiskCode ? $row->code : FiscalRiskCode::tryFrom((string) $row->code);
            $severity = $row->severity;

            return [
                'code' => $code instanceof FiscalRiskCode ? $code->value : (string) $row->code,
                'label' => $code instanceof FiscalRiskCode ? $code->name : (string) $row->code,
                'severity' => $severity instanceof \BackedEnum ? (string) $severity->value : (string) $severity,
                'total' => (int) $row->getAttribute('aggregate'),
            ];
        })->values()->all();

        return $list;
    }
}
