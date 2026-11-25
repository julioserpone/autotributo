<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F5-07 · Confiabilidad de los agregados del 104.
 *
 * Dos correcciones:
 *
 * 1. `fiscal_periods` guarda el resultado del último cálculo de agregados. Hasta
 *    ahora un fallo del recálculo solo quedaba en el log y el período aparecía
 *    como «sin comprobantes».
 * 2. La clave única de `period_aggregates` incluía la tarifa, pero el cálculo
 *    agrupa por `codigoPorcentaje`: cuando el XML trae `tarifa = 0` y el catálogo
 *    la tarifa real, se insertaban dos filas con la misma clave y la transacción
 *    entera se revertía (`period_aggregates_unique_bucket`). Ahora la tarifa es
 *    un atributo de la fila y la tarifa del período manda en la clave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_periods', function (Blueprint $table): void {
            $table->string('aggregation_status', 16)->default('pending');
            $table->string('aggregation_error', 500)->nullable();
            $table->unsignedSmallInteger('aggregation_attempts')->default(0);
            $table->timestampTz('aggregated_at')->nullable();
            $table->index(['aggregation_status', 'aggregated_at']);
        });

        // Consolida filas que hoy chocan por tarifa (0 del XML frente a la del
        // catálogo) antes de retirar la tarifa de la clave única.
        $this->collapseDuplicateRates();

        Schema::table('period_aggregates', function (Blueprint $table): void {
            $table->dropUnique('period_aggregates_unique_bucket');
            $table->unique(
                ['fiscal_period_id', 'direction', 'document_type', 'tax_code', 'sri_percentage_code', 'rate'],
                'period_aggregates_unique_bucket',
            );
        });
    }

    public function down(): void
    {
        Schema::table('period_aggregates', function (Blueprint $table): void {
            $table->dropUnique('period_aggregates_unique_bucket');
            $table->unique(
                ['fiscal_period_id', 'direction', 'document_type', 'tax_code', 'sri_percentage_code'],
                'period_aggregates_unique_bucket',
            );
        });

        Schema::table('fiscal_periods', function (Blueprint $table): void {
            $table->dropIndex(['aggregation_status', 'aggregated_at']);
            $table->dropColumn(['aggregation_status', 'aggregation_error', 'aggregation_attempts', 'aggregated_at']);
        });
    }

    private function collapseDuplicateRates(): void
    {
        $duplicates = DB::table('period_aggregates')
            ->selectRaw('fiscal_period_id, direction, document_type, tax_code, sri_percentage_code, COUNT(*) AS total')
            ->groupBy('fiscal_period_id', 'direction', 'document_type', 'tax_code', 'sri_percentage_code')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $rows = DB::table('period_aggregates')
                ->where('fiscal_period_id', $duplicate->fiscal_period_id)
                ->where('direction', $duplicate->direction)
                ->where('document_type', $duplicate->document_type)
                ->where('tax_code', $duplicate->tax_code)
                ->where('sri_percentage_code', $duplicate->sri_percentage_code)
                ->orderByDesc('rate')
                ->orderBy('id')
                ->get();

            $keeper = $rows->first();

            if ($keeper === null) {
                continue;
            }

            $others = $rows->slice(1);

            DB::table('period_aggregates')->where('id', $keeper->id)->update([
                'base_cents' => $rows->sum(static fn (object $row): int => (int) $row->base_cents),
                'tax_cents' => $rows->sum(static fn (object $row): int => (int) $row->tax_cents),
                'retention_cents' => $rows->sum(static fn (object $row): int => (int) $row->retention_cents),
                'documents_count' => $rows->sum(static fn (object $row): int => (int) $row->documents_count),
            ]);

            DB::table('period_aggregates')->whereIn('id', $others->pluck('id')->all())->delete();
        }
    }
};
