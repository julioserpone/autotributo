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
use Illuminate\Support\Facades\DB;

/**
 * Recalcula los agregados materializados de un período fiscal.
 *
 * La operación es idempotente: borra los agregados previos del período y los
 * regenera a partir de los comprobantes persistidos. Se puede invocar
 * múltiples veces sin duplicar filas.
 */
final readonly class ComputePeriodAggregates
{
    public function handle(Company $company, int $year, int $month): FiscalPeriod
    {
        $tenant = Tenant::query()->findOrFail($company->tenant_id);

        app(TenantContext::class)->initialize($tenant);

        return DB::transaction(function () use ($company, $year, $month): FiscalPeriod {
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
                ],
            );

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
             * detalle), así que el impuesto de cabecera llegaba con tarifa 0.
             * El catálogo versionado sí la tiene: se usa como fuente.
             */
            $ratesByPercentageCode = TaxRate::query()
                ->where('code', 'IVA')
                ->where('valid_from', '<=', $end->toDateString())
                ->where(function ($query) use ($end): void {
                    $query->whereNull('valid_to')
                        ->orWhere('valid_to', '>=', $end->toDateString());
                })
                ->orderByDesc('valid_from')
                ->get()
                ->unique('sri_percentage_code')
                ->mapWithKeys(static fn (TaxRate $rate): array => [(string) $rate->sri_percentage_code => (string) (float) $rate->rate])
                ->all();

            /** @var array<string, array<string, mixed>> $buckets */
            $buckets = [];

            foreach ($documents as $document) {
                $direction = $document->document_type->tradeDirection($document->direction);

                foreach ($document->taxes as $tax) {
                    $rate = $this->effectiveRate((string) $tax->rate, (string) $tax->sri_percentage_code, $ratesByPercentageCode);

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

            return $period;
        });
    }

    /**
     * Tarifa efectiva del impuesto: la del comprobante si la publica; si no, la
     * del catálogo para ese `codigoPorcentaje`.
     *
     * @param  array<string, string>  $ratesByPercentageCode
     */
    private function effectiveRate(string $documentRate, string $percentageCode, array $ratesByPercentageCode): string
    {
        if ((float) $documentRate > 0.0) {
            return $documentRate;
        }

        return $ratesByPercentageCode[$percentageCode] ?? $documentRate;
    }
}
