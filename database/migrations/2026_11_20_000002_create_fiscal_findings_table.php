<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            // `fiscal_periods` se crea en F5; la columna queda lista y sin FK
            // para no acoplar F3-06 a un módulo aún no implementado.
            $table->unsignedBigInteger('fiscal_period_id')->nullable();
            $table->string('code', 40);
            $table->string('severity', 16);
            $table->string('title', 200);
            $table->text('description');
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->jsonb('metadata')->nullable();
            $table->string('status', 20)->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'status']);
            $table->index(['tenant_id', 'code', 'status']);
            $table->index(['source_type', 'source_id']);
        });

        // Unique parcial: no se duplican hallazgos abiertos con la misma
        // procedencia. Una vez resueltos/descartados se permite reabrir.
        DB::statement(
            'CREATE UNIQUE INDEX fiscal_findings_open_unique '
            .'ON fiscal_findings (tenant_id, code, source_type, source_id) '
            ."WHERE status = 'open'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS fiscal_findings_open_unique');
        Schema::dropIfExists('fiscal_findings');
    }
};
