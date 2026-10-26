<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sri_robot_callbacks', function (Blueprint $table): void {
            $table->id();
            // El robot garantiza un `event_id` por invocación; la unicidad es
            // la deduplicación exigida por el contrato (F2-04).
            $table->string('event_id', 128)->unique();
            $table->foreignId('run_id')->nullable()->constrained('sri_sync_runs')->nullOnDelete();
            $table->string('status', 20);
            $table->string('request_id', 128)->nullable();
            $table->string('ruc', 13)->nullable();
            $table->jsonb('periods')->nullable();
            $table->jsonb('payload');
            $table->unsignedBigInteger('signature_timestamp');
            $table->timestampTz('received_at');
            $table->timestampsTz();

            $table->index(['run_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sri_robot_callbacks');
    }
};
