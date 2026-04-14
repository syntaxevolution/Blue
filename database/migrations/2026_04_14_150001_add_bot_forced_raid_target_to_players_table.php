<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds two columns to `players` supporting the `bots:swarm` admin
 * command — a temporary forced-goal override that makes every bot
 * drop whatever they were doing and march on a specific player's
 * base until they've attacked once.
 *
 *   bot_forced_raid_target_player_id — the victim's player id, NULL
 *                                      when no swarm is active on
 *                                      this bot.
 *   bot_forced_raid_expires_at       — safety TTL. Cleared by the
 *                                      planner when it reads a past
 *                                      expiry so a stale target
 *                                      never wedges a bot forever.
 *
 * Both columns sit alongside the existing bot_* state on `players`
 * so the goal planner can check them in the same row fetch it
 * already does. nullOnDelete on the FK so hard-deleting a victim
 * cleanly clears any outstanding swarm targeting that victim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->foreignId('bot_forced_raid_target_player_id')
                ->nullable()
                ->after('bot_consecutive_drill_count')
                ->constrained('players')
                ->nullOnDelete();

            $table->timestamp('bot_forced_raid_expires_at')
                ->nullable()
                ->after('bot_forced_raid_target_player_id');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropForeign(['bot_forced_raid_target_player_id']);
            $table->dropColumn([
                'bot_forced_raid_target_player_id',
                'bot_forced_raid_expires_at',
            ]);
        });
    }
};
