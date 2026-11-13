<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 8);
            $table->string('sri_percentage_code', 2);
            $table->decimal('rate', 9, 6);
            $table->string('label', 160);
            $table->string('applies_to', 40)->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('legal_basis', 512)->nullable();
            $table->string('source', 512);
            $table->date('published_at')->nullable();
            $table->char('checksum', 64)->nullable();
            $table->timestampsTz();
            $table->unique(['code', 'sri_percentage_code', 'valid_from']);
            $table->index(['sri_percentage_code', 'valid_from', 'valid_to']);
        });

        Schema::create('retention_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('tax_type', 8);
            $table->string('code', 8);
            $table->decimal('rate', 9, 6);
            $table->string('label', 200);
            $table->string('applies_to', 40)->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('legal_basis', 512)->nullable();
            $table->string('source', 512);
            $table->date('published_at')->nullable();
            $table->char('checksum', 64)->nullable();
            $table->timestampsTz();
            $table->unique(['tax_type', 'code', 'valid_from']);
            $table->index(['tax_type', 'valid_from', 'valid_to']);
        });

        Schema::create('form_lines', function (Blueprint $table): void {
            $table->id();
            $table->string('form_code', 8);
            $table->string('line_code', 8);
            $table->string('section', 40);
            $table->string('label', 240);
            $table->string('data_type', 16);
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('display_order');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('source', 512);
            $table->date('published_at')->nullable();
            $table->char('checksum', 64)->nullable();
            $table->timestampsTz();
            $table->unique(['form_code', 'line_code', 'valid_from']);
            $table->index(['form_code', 'valid_from', 'valid_to']);
            $table->index(['form_code', 'display_order']);
        });

        Schema::create('personal_expense_caps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('fiscal_year');
            $table->string('category_code', 40)->default('global');
            $table->unsignedSmallInteger('dependents')->default(0);
            $table->bigInteger('cap_cents');
            $table->unsignedSmallInteger('baskets')->nullable();
            $table->bigInteger('basic_food_basket_cents');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('source', 512);
            $table->date('published_at')->nullable();
            $table->char('checksum', 64)->nullable();
            $table->timestampsTz();
            $table->unique(['fiscal_year', 'category_code', 'dependents', 'valid_from']);
            $table->index(['fiscal_year', 'valid_from', 'valid_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_expense_caps');
        Schema::dropIfExists('form_lines');
        Schema::dropIfExists('retention_rates');
        Schema::dropIfExists('tax_rates');
    }
};
