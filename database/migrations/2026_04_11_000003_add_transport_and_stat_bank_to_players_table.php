<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds fields for:
 *   - active_transport      — which transport mode the player is currently using.
 *                             'walking' is the implicit default (always owned).
 *   - {stat}_banked         — overflow buffer for stat-add purchases that would
 *                             exceed stats.hard_cap. Drains automatically when
 *                             the cap is raised, via StatOverflowService::drainBank().
 *   - broken_item_key       — nullable pointer to a broken item_key in player_items.
 *                             When non-null, BlockOnBrokenItem middleware rejects
 *                             all game actions until the player repairs or abandons.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->string('active_transport', 32)->default('walking')->after('drill_tier');

            $table->unsignedInteger('strength_banked')->default(0)->after('strength');
            $table->unsignedInteger('fortification_banked')->default(0)->after('fortification');
            $table->unsignedInteger('stealth_banked')->default(0)->after('stealth');
            $table->unsignedInteger('security_banked')->default(0)->after('security');

            $table->string('broken_item_key', 64)->nullable()->after('active_transport');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn([
                'active_transport',
                'strength_banked',
                'fortification_banked',
                'stealth_banked',
                'security_banked',
                'broken_item_key',
            ]);
        });
    }
};
