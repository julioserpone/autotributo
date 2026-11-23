<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_aggregates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('fiscal_period_id')->constrained('fiscal_periods')->cascadeOnDelete();
            $table->string('direction', 16);
            $table->string('document_type', 8);
            $table->string('tax_code', 8);
            $table->string('sri_percentage_code', 4)->default('');
            $table->decimal('rate', 9, 6)->default(0);
            $table->bigInteger('base_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('retention_cents')->default(0);
            $table->unsignedInteger('documents_count')->default(0);
            $table->timestampTz('computed_at');
            $table->unsignedSmallInteger('version')->default(1);
            $table->timestampsTz();

            $table->unique(['fiscal_period_id', 'direction', 'document_type', 'tax_code', 'sri_percentage_code'], 'period_aggregates_unique_bucket');
            $table->index(['tenant_id', 'company_id', 'fiscal_period_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_aggregates');
    }
};
