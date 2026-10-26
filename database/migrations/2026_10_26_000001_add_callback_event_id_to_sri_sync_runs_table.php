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
            // Correlaciona el run con el callback firmado del robot. Es único
            // porque el robot lo reporta como identificador del evento. Se usa
            // varchar (no `ulid`) para no rellenar con espacios en Postgres y
            // admitir el `sri-<uuid>` que genera el robot si falta.
            $table->string('callback_event_id', 128)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('sri_sync_runs', function (Blueprint $table): void {
            $table->dropUnique(['callback_event_id']);
            $table->dropColumn('callback_event_id');
        });
    }
};
