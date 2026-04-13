<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replace the per-action scout state with a persistent goal slot.
 *
 * Old model (scout): each tick rolled N independent weighted actions,
 * each of which might travel one tile toward its own private target.
 * Net movement averaged out to near-zero because the 3 actions per tick
 * usually pointed at 3 different tiles. Bots hovered instead of
 * converging on anything. Sticky scout direction helped scouting but
 * did nothing for the zig-zag between drill / shop / spy / attack.
 *
 * New model (goal): tick() loads a single bot_current_goal, spends the
 * whole per-tick move budget pushing toward that goal, and only
 * replans when it completes, invalidates, or times out. Fail counter
 * auto-clears a broken goal after 3 consecutive step() throws so one
 * bad target can't wedge a bot forever.
 *
 * Goal JSON shape is a discriminated union keyed on `kind`:
 *   drill | shop | spy | raid | sabotage | explore
 * See BotGoalPlanner for the per-kind fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->json('bot_current_goal')->nullable()->after('bot_moves_budget');
            $table->timestamp('bot_goal_expires_at')->nullable()->after('bot_current_goal');
            $table->unsignedTinyInteger('bot_goal_fail_count')->default(0)->after('bot_goal_expires_at');
        });

        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn(['bot_scout_direction', 'bot_scout_ticks_held']);
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->char('bot_scout_direction', 1)->nullable()->after('bot_moves_budget');
            $table->unsignedSmallInteger('bot_scout_ticks_held')->default(0)->after('bot_scout_direction');
        });

        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn(['bot_current_goal', 'bot_goal_expires_at', 'bot_goal_fail_count']);
        });
    }
};
