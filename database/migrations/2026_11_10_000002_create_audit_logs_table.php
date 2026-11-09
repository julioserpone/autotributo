<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría base (F1-07).
 *
 * La tabla sigue `docs/03` §9 y es la que usa `owen-it/laravel-auditing` como
 * driver `database`. `tenant_id` es nullable porque hay eventos globales
 * (login antes de resolver el tenant, soporte). Los valores sensibles se
 * redactan en el modelo, nunca aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            // El paquete resuelve el actor como morph (`user_type` + `user_id`).
            $table->string('user_type')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('event', 64);
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->jsonb('tags')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['tenant_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
