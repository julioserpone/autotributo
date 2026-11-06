<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda el resultado de importar cada comprobante en `sri_sync_run_files`.
 *
 * El run conserva los totales agregados; estas columnas permiten mostrar y
 * reintentar el detalle por documento sin volver a consultar el SRI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sri_sync_run_files', function (Blueprint $table): void {
            $table->string('error_code', 40)->nullable()->after('error_message');
            $table->string('import_outcome', 16)->nullable()->after('error_code');
            $table->string('source', 8)->nullable()->after('import_outcome');
            $table->index(['run_id', 'import_outcome']);
        });
    }

    public function down(): void
    {
        Schema::table('sri_sync_run_files', function (Blueprint $table): void {
            $table->dropIndex(['run_id', 'import_outcome']);
            $table->dropColumn(['error_code', 'import_outcome', 'source']);
        });
    }
};
