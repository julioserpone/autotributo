<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('economic_activity_codes', function (Blueprint $table): void {
            $table->string('code', 10)->primary();
            $table->string('description', 500);
            $table->string('level', 40)->default('SUBNIVEL ACTIVIDAD');
            $table->timestamps();

            $table->index('description');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('economic_activity_codes');
    }
};
