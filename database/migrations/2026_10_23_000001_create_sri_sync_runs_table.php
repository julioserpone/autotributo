<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sri_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('queued')->index();
            $table->date('date_from');
            $table->date('date_to');
            $table->jsonb('document_types');
            $table->string('idempotency_key', 64)->unique();
            $table->string('lambda_request_id', 128)->nullable();
            $table->string('s3_prefix', 512)->nullable();
            $table->jsonb('totals')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sri_sync_runs');
    }
};
