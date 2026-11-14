<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classification_rules', function (Blueprint $table): void {
            $table->id();
            // `tenant_id` nulo identifica una regla global (catálogo base);
            // las reglas del estudio siempre llevan tenant.
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->string('match_type', 20);
            $table->string('pattern', 255);
            $table->foreignId('expense_category_id')->constrained('expense_categories');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('hits_count')->default(0);
            $table->timestampTz('last_matched_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['tenant_id', 'is_active', 'priority']);
            $table->index(['tenant_id', 'match_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classification_rules');
    }
};
