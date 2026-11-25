<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Actions;

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Models\FiscalPeriod;
use App\Domain\Fiscal\Models\PeriodAggregate;
use App\Domain\Fiscal\Models\TaxRate;
use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recalcula los agregados materializados de un período fiscal.
 *
 * La operación es idempotente: borra los agregados previos del período y los
 * regenera a partir de los comprobantes persistidos. Se puede invocar
 * múltiples veces sin duplicar filas.
 *
 * F5-07: el resultado se persiste en `fiscal_periods.aggregation_*`. Antes un
 * fallo revertía la transacción y el `catch` de `FinalizeSriRun` solo lo
 * reportaba, así que un período sin agregados era indistinguible de un período
 * sin comprobantes.
 */
final readonly class ComputePeriodAggregates
{
    /**
     * Códigos de porcentaje del IVA que el catálogo debe conocer. Si un
     * comprobante trae otro, se conserva su tarifa pero queda un aviso: una
     * tarifa desconocida es una cifra fiscal sin respaldo normativo.
     */
    public function handle(Company $company, int $year, int $month): FiscalPeriod
    {
        $tenant = Tenant::query()->findOrFail($company->tenant_id);

        app(TenantContext::class)->initialize($tenant);

        $period = FiscalPeriod::query()->firstOrCreate(
            [
                'company_id' => $company->id,
                'year' => $year,
                'month' => $month,
            ],
            [
                'tenant_id' => $company->tenant_id,
                'status' => 'open',
                'opened_at' => now(),
                'aggregation_status' => FiscalPeriod::AGGREGATION_PENDING,
            ],
        );

        try {
            DB::transaction(fn (): int => $this->rebuild($company, $period, $year, $month));
        } catch (Throwable $exception) {
            $period->markAggregationFailed($exception->getMessage());

            Log::error('fiscal.aggregation_failed', [
                'tenant_id' => $company->tenant_id,
                'company_id' => $company->id,
                'period' => sprintf('%04d-%02d', $year, $month),
                'reason' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $period->markAggregated();

        return $period->refresh();
    }

    /**
     * Ejecuta el recálculo sin propagar la excepción.
     *
     * Se usa desde la sincronización del SRI, donde un fallo de agregación no
     * debe cambiar el veredicto del run; el motivo queda en el período
     * (`aggregation_status = failed` + `aggregation_error`) y en el log.
     *
     * @return bool si la agregación terminó bien
     */
    public function attempt(Company $company, int $year, int $month): bool
    {
        try {
            $this->handle($company, $year, $month);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Borra y regenera los agregados del período dentro de una transacción.
     *
     * @return int número de filas escritas
     */
    private function rebuild(Company $company, FiscalPeriod $period, int $year, int $month): int
    {
        PeriodAggregate::query()
            ->where('fiscal_period_id', $period->id)
            ->delete();

        $start = Carbon::parse(sprintf('%04d-%02d-01', $year, $month))->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $documents = FiscalDocument::query()
            ->with(['taxes', 'retentions'])
            ->where('company_id', $company->id)
            ->whereBetween('issue_date', [$start->toDateString(), $end->toDateString()])
            ->get();

        /**
         * Tarifa por código de porcentaje del SRI vigente a fin del período.
         *
         * La factura no publica `<tarifa>` en `totalImpuesto` (solo en el
         * detalle), así que el impuesto de cabecera llega con tarifa 0. El
         * catálogo versionado sí la tiene: se usa como fuente.
         */
        $rates = TaxRate::query()
            ->where('code', 'IVA')
            ->where('valid_from', '<=', $end->toDateString())
            ->where(function ($query) use ($end): void {
                $query->whereNull('valid_to')
                    ->orWhere('valid_to', '>=', $end->toDateString());
            })
            ->orderByDesc('valid_from')
            ->get();

        $ratesByPercentageCode = $this->ratesByPercentageCode($rates);

        /** @var array<string, array<string, mixed>> $buckets */
        $buckets = [];
        /** @var array<string, true> $unknownCodes */
        $unknownCodes = [];

        foreach ($documents as $document) {
            $direction = $document->document_type->tradeDirection($document->direction);

            foreach ($document->taxes as $tax) {
                [$rate, $known] = $this->effectiveRate(
                    (string) $tax->rate,
                    (string) $tax->sri_percentage_code,
                    $ratesByPercentageCode,
                );

                if (! $known) {
                    $unknownCodes[(string) $tax->sri_percentage_code] = true;
                }

                $key = implode('|', [
                    $direction,
                    $document->document_type->value,
                    $tax->tax_code,
                    $tax->sri_percentage_code,
                    $rate,
                ]);

                if (! isset($buckets[$key])) {
                    $buckets[$key] = [
                        'direction' => $direction,
                        'document_type' => $document->document_type->value,
                        'tax_code' => $tax->tax_code,
                        'sri_percentage_code' => $tax->sri_percentage_code,
                        'rate' => $rate,
                        'base_cents' => 0,
                        'tax_cents' => 0,
                        'retention_cents' => 0,
                        'documents' => [],
                    ];
                }

                $buckets[$key]['base_cents'] += $tax->base_cents;
                $buckets[$key]['tax_cents'] += $tax->amount_cents;
                $buckets[$key]['documents'][$document->id] = true;
            }

            foreach ($document->retentions as $retention) {
                $key = implode('|', [
                    $direction,
                    $document->document_type->value,
                    $retention->retention_code,
                    '',
                    (string) $retention->rate,
                ]);

                if (! isset($buckets[$key])) {
                    $buckets[$key] = [
                        'direction' => $direction,
                        'document_type' => $document->document_type->value,
                        'tax_code' => $retention->retention_code,
                        'sri_percentage_code' => '',
                        'rate' => $retention->rate,
                        'base_cents' => 0,
                        'tax_cents' => 0,
                        'retention_cents' => 0,
                        'documents' => [],
                    ];
                }

                $buckets[$key]['base_cents'] += $retention->base_cents;
                $buckets[$key]['retention_cents'] += $retention->amount_cents;
                $buckets[$key]['documents'][$document->id] = true;
            }
        }

        if ($unknownCodes !== []) {
            Log::warning('fiscal.unknown_percentage_codes', [
                'tenant_id' => $company->tenant_id,
                'company_id' => $company->id,
                'period' => sprintf('%04d-%02d', $year, $month),
                'codes' => array_keys($unknownCodes),
            ]);
        }

        $now = now();

        foreach ($buckets as $bucket) {
            PeriodAggregate::query()->create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $company->id,
                'fiscal_period_id' => $period->id,
                'direction' => $bucket['direction'],
                'document_type' => $bucket['document_type'],
                'tax_code' => $bucket['tax_code'],
                'sri_percentage_code' => $bucket['sri_percentage_code'],
                'rate' => $bucket['rate'],
                'base_cents' => $bucket['base_cents'],
                'tax_cents' => $bucket['tax_cents'],
                'retention_cents' => $bucket['retention_cents'],
                'documents_count' => count($bucket['documents']),
                'computed_at' => $now,
                'version' => 1,
            ]);
        }

        return count($buckets);
    }

    /**
     * Tarifa vigente por `codigoPorcentaje`, sin repetir códigos.
     *
     * La consulta viene ordenada por `valid_from` descendente, así que la
     * primera fila de cada código es la vigente al fin del período.
     *
     * @param  Collection<int, TaxRate>  $rates
     * @return array<string, string>
     */
    private function ratesByPercentageCode($rates): array
    {
        $map = [];

        foreach ($rates as $rate) {
            $code = (string) $rate->sri_percentage_code;

            if (! isset($map[$code])) {
                $map[$code] = (string) $rate->rate;
            }
        }

        return $map;
    }

    /**
     * Tarifa efectiva del impuesto: la del comprobante si la publica; si no, la
     * del catálogo para ese `codigoPorcentaje`.
     *
     * Devuelve también si el código se resolvió con el catálogo. La tarifa se
     * conserva como cadena decimal: nunca se convierte a `float` en la ruta de
     * dinero (ADR-0002).
     *
     * @param  array<string, string>  $ratesByPercentageCode
     * @return array{0: string, 1: bool}
     */
    private function effectiveRate(string $documentRate, string $percentageCode, array $ratesByPercentageCode): array
    {
        if ((float) $documentRate > 0.0) {
            return [$documentRate, true];
        }

        $catalogRate = $ratesByPercentageCode[$percentageCode] ?? null;

        if ($catalogRate !== null) {
            return [$catalogRate, true];
        }

        // Tarifa 0 declarada por el comprobante: es un valor legítimo, no un
        // código desconocido (p. ej. `codigoPorcentaje = 0`).
        if (trim($percentageCode) === '0') {
            return [$documentRate, true];
        }

        return [$documentRate, false];
    }
}
