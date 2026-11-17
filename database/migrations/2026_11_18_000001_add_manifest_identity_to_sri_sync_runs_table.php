<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sri_sync_runs', function (Blueprint $table): void {
            // Identidad de la ejecución del robot que generó el manifiesto
            // consumido por este run. Permite distinguir una ejecución real de
            // una reutilización de archivos viejos en S3 (ver F2-05/F2-10c).
            $table->string('manifest_event_id', 128)->nullable();
            $table->timestamp('manifest_generated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sri_sync_runs', function (Blueprint $table): void {
            $table->dropColumn(['manifest_event_id', 'manifest_generated_at']);
        });
    }
};
