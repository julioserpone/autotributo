<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->char('ruc', 13);
            $table->string('legal_name', 300);
            $table->string('trade_name', 300)->nullable();
            $table->string('regime', 32)->default('general');
            $table->boolean('is_accounting_required')->default(true);
            $table->boolean('is_withholding_agent')->default(false);
            $table->string('withholding_resolution', 64)->nullable();
            $table->string('economic_activity_code', 10)->nullable();
            $table->string('address', 300)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('phone', 40)->nullable();
            $table->smallInteger('fiscal_year_start_month')->default(1);
            $table->string('status', 20)->default('active')->index();
            $table->jsonb('settings')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'ruc']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
