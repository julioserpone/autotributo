<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sri_credentials', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            // RUC o usuario del portal. Se guarda en claro: es público.
            $table->char('username', 13);
            // Contraseña cifrada con SRI_CREDENTIALS_KEY (sodium secretbox).
            $table->text('password_ciphertext');
            // Versión de la clave usada; permite rotar sin reescribir filas.
            $table->smallInteger('key_version')->default(1);
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('last_failure_at')->nullable();
            $table->smallInteger('failure_count')->default(0);
            $table->timestampTz('rotated_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'company_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('sri_credential_accesses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('purpose', 20);
            $table->string('ip', 45)->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['tenant_id', 'company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sri_credential_accesses');
        Schema::dropIfExists('sri_credentials');
    }
};
