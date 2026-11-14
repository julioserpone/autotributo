<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('invoice_item_id')->constrained('invoice_items')->cascadeOnDelete();
            $table->foreignId('expense_category_id')->constrained('expense_categories');
            $table->string('method', 16);
            $table->decimal('confidence', 5, 4)->default(1);
            $table->string('status', 16)->default('suggested');
            $table->foreignId('rule_id')->nullable()->constrained('classification_rules')->nullOnDelete();
            // F3-04 creará `llm_calls`; la columna queda lista para entonces.
            $table->unsignedBigInteger('llm_call_id')->nullable();
            $table->string('prompt_version', 32)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();

            $table->unique('invoice_item_id');
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'expense_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classifications');
    }
};
