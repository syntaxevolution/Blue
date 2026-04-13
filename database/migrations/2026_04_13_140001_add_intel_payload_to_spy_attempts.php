<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spy_attempts', function (Blueprint $table) {
            $table->json('intel_payload')->nullable()->after('rng_output');
        });
    }

    public function down(): void
    {
        Schema::table('spy_attempts', function (Blueprint $table) {
            $table->dropColumn('intel_payload');
        });
    }
};
