<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_signoff_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->string('stage_key', 100);
            $table->unsignedSmallInteger('stage_version');
            $table->char('stage_fingerprint', 64);
            $table->string('decision', 24);
            $table->text('comments')->nullable();
            $table->timestampTz('reviewed_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'stage_key', 'stage_version', 'reviewed_at'], 'fiscal_signoff_reviews_stage_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_signoff_reviews');
    }
};
