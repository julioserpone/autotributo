<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->smallInteger('line_number');
            $table->string('main_code', 50)->nullable();
            $table->string('auxiliary_code', 50)->nullable();
            $table->string('description', 1000);
            $table->string('normalized_description', 1000);
            $table->decimal('quantity', 18, 6)->default(0);
            $table->decimal('unit_price', 18, 6)->default(0);
            $table->decimal('discount', 18, 6)->default(0);
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->foreignId('expense_category_id')->nullable();
            $table->string('classification_status', 20)->default('pending');
            $table->timestampsTz();
            $table->index(['tenant_id', 'invoice_id']);
            $table->index(['tenant_id', 'classification_status']);
            $table->unique(['invoice_id', 'line_number']);
        });
        Schema::create('invoice_taxes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('tax_code', 8);
            $table->string('sri_percentage_code', 4);
            $table->decimal('rate', 9, 6)->default(0);
            $table->bigInteger('base_cents')->default(0);
            $table->bigInteger('amount_cents')->default(0);
            $table->foreignId('tax_rate_id')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'invoice_id']);
        });
        Schema::create('retention_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('tax_type', 8);
            $table->string('retention_code', 8);
            $table->bigInteger('base_cents')->default(0);
            $table->decimal('rate', 9, 6)->default(0);
            $table->bigInteger('amount_cents')->default(0);
            $table->foreignId('retention_rate_id')->nullable();
            $table->string('document_number', 32)->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_lines');
        Schema::dropIfExists('invoice_taxes');
        Schema::dropIfExists('invoice_items');
    }
};
