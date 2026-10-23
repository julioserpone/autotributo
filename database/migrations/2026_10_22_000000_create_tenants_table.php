<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name', 160);
            $table->string('slug', 63)->unique();
            $table->string('status', 20)->default('active')->index();
            $table->string('plan', 32)->default('starter');
            $table->jsonb('settings')->nullable();
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
