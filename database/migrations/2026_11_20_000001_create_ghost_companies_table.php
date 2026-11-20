<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ghost_companies', function (Blueprint $table): void {
            $table->char('ruc', 13)->primary();
            $table->string('legal_name', 300);
            $table->string('resolution', 128)->nullable();
            $table->date('published_at');
            $table->string('source_url', 512);
            $table->string('status', 20)->default('active');
            $table->timestampTz('imported_at');
            $table->timestampsTz();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ghost_companies');
    }
};
