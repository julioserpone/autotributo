<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->char('document_type', 2);
            $table->char('access_key', 49);
            $table->string('authorization_number', 64)->nullable();
            $table->char('establishment', 3);
            $table->char('emission_point', 3);
            $table->char('sequential', 9);
            $table->date('issue_date');
            $table->timestampTz('authorization_date')->nullable();
            $table->string('status', 20)->default('authorized');
            $table->char('environment', 1);
            $table->string('direction', 16)->default('received');
            $table->char('issuer_ruc', 13);
            $table->string('issuer_name', 300);
            $table->char('receiver_ruc', 13)->nullable();
            $table->string('receiver_name', 300)->nullable();
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('discount_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('ice_cents')->default(0);
            $table->bigInteger('irbpnr_cents')->default(0);
            $table->bigInteger('tip_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->char('currency', 3)->default('USD');
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->string('payment_method', 32)->nullable();
            $table->string('xml_path', 512);
            $table->char('xml_sha256', 64);
            $table->integer('xml_size');
            $table->jsonb('raw')->nullable();
            $table->string('source', 20)->default('sri_robot');
            $table->boolean('totals_mismatch')->default(false);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('updated_or_created_at');
            $table->foreignId('sync_run_id')->nullable()->constrained('sri_sync_runs')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'company_id', 'access_key']);
            $table->index(['tenant_id', 'company_id', 'issue_date']);
            $table->index(['tenant_id', 'company_id', 'issuer_ruc']);
            $table->index(['tenant_id', 'company_id', 'document_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
