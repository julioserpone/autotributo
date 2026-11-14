<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->foreignId('parent_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $table->boolean('is_personal_deductible')->default(false);
            $table->boolean('is_business_deductible')->default(false);
            $table->string('icon', 40)->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('source', 512);
            $table->timestampsTz();

            $table->index(['is_personal_deductible', 'valid_from']);
            $table->index(['parent_id', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
