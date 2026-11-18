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
            // Indica si el run pidió re-descargar e importar los comprobantes
            // aunque ya estuvieran registrados (forzar re-descarga).
            $table->boolean('force')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('sri_sync_runs', function (Blueprint $table): void {
            $table->dropColumn('force');
        });
    }
};
