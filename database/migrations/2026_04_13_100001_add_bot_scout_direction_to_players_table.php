<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persistent scout heading for bot players.
 *
 * Why a dedicated column instead of just running the scout loop more
 * aggressively: the old BotDecisionService nulled $this->scoutDirection
 * at the start of every tick, so a bot's effective walking pattern was
 * a pure random walk that only covered sqrt(N) distance in N steps.
 * With the 5-min tick cadence and ~200 moves/day daily regen, a bot
 * got roughly 0.7 moves per tick and drifted maybe 7 tiles from spawn
 * after 6 hours of wall-clock time — never discovering oil fields or
 * posts, never earning barrels, never advancing skills.
 *
 * Making the direction sticky across ticks turns that into a straight
 * line: the bot commits to "walk east until something interesting
 * shows up, or until I've held this heading for ~20 ticks." A held
 * counter prevents permanent stuckness against the world edge and
 * lets us tune the re-roll cadence from config.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            // 'n' | 's' | 'e' | 'w' | null. Null = "no committed heading,
            // pick a fresh one next time a scout walk fires."
            $table->char('bot_scout_direction', 1)->nullable()->after('bot_moves_budget');

            // How many consecutive ticks this direction has been held
            // without finding a target. Reset to 0 on target find or
            // when a new direction is rolled. Re-rolls when it reaches
            // bots.scout_max_ticks_per_direction.
            $table->unsignedSmallInteger('bot_scout_ticks_held')->default(0)->after('bot_scout_direction');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn(['bot_scout_direction', 'bot_scout_ticks_held']);
        });
    }
};
