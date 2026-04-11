<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `depleted_at` to oil_fields. Set by DrillService when the last
 * undrilled cell on a field is drilled; cleared by OilFieldRegenService
 * once the field has been fully depleted for `drilling.field_refill_hours`
 * and all drill points are reset back to undrilled.
 *
 * Indexed so the lazy reconciler can filter "candidate fields to regen"
 * cheaply (though in practice regen runs on-read, per field).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('oil_fields', 'depleted_at')) {
            return;
        }

        Schema::table('oil_fields', function (Blueprint $table) {
            $table->timestamp('depleted_at')->nullable()->after('last_regen_at');
            $table->index('depleted_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('oil_fields', 'depleted_at')) {
            return;
        }

        Schema::table('oil_fields', function (Blueprint $table) {
            $table->dropIndex(['depleted_at']);
            $table->dropColumn('depleted_at');
        });
    }
};
