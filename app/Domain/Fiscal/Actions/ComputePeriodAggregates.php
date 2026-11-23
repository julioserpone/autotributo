<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Actions;

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Models\FiscalPeriod;
use App\Domain\Fiscal\Models\PeriodAggregate;
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
                ->where('company_id', $company->id)
                ->whereBetween('issue_date', [$start->toDateString(), $end->toDateString()])
                ->get();

            /** @var array<string, array<string, mixed>> $buckets */
            $buckets = [];

            foreach ($documents as $document) {
                $direction = 'purchases'; // MVP: solo comprobantes recibidos.

                foreach ($document->taxes as $tax) {
                    $key = implode('|', [
                        $direction,
                        $document->document_type->value,
                        $tax->tax_code,
                        $tax->sri_percentage_code,
                        (string) $tax->rate,
                    ]);

                    if (! isset($buckets[$key])) {
                        $buckets[$key] = [
                            'direction' => $direction,
                            'document_type' => $document->document_type->value,
                            'tax_code' => $tax->tax_code,
                            'sri_percentage_code' => $tax->sri_percentage_code,
                            'rate' => $tax->rate,
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
}
