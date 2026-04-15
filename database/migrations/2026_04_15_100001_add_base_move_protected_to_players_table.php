<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `base_move_protected` to `players`.
 *
 * Flag set to true when a player buys the Deadbolt Plinth item from the
 * general store. Once set, rival use of Abduction Anchor against this
 * player's base is rejected at the BaseTeleportService guard. The flag
 * is permanent — there is no spend/consume path. The Deadbolt Plinth
 * deliberately does NOT live in `player_items` (and therefore not in
 * the toolbox HUD): it's a set-and-forget passive, so the canonical
 * record is this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->boolean('base_move_protected')
                ->default(false)
                ->after('immunity_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn('base_move_protected');
        });
    }
};
