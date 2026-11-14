<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_calls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('purpose', 32);
            $table->string('provider', 32);
            $table->string('model', 64);
            $table->string('prompt_version', 32)->nullable();
            // SHA-256 de la entrada normalizada (descripción, rubro, catálogo,
            // modelo y prompt). No se guarda la descripción cruda.
            $table->char('input_hash', 64);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cost_micros')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('status', 16);
            $table->string('error_code', 48)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            // Presupuesto mensual por tenant y búsqueda de caché.
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'input_hash']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('classifications', function (Blueprint $table): void {
            $table->foreign('llm_call_id')
                ->references('id')
                ->on('llm_calls')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('classifications', function (Blueprint $table): void {
            $table->dropForeign(['llm_call_id']);
        });

        Schema::dropIfExists('llm_calls');
    }
};
