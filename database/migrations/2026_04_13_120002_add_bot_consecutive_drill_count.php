<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track how many consecutive drill goals a bot has picked without
 * interleaving a different action. Used by BotGoalPlanner to force a
 * diversification break once the streak reaches
 * bots.force_explore_after_drills, so bots don't spend every waking
 * tick on the nearest oil field and actually use the rest of the
 * feature set (shop, spy, sabotage, raid, explore).
 *
 * Counter increments whenever the planner returns a `drill` kind and
 * resets to zero on any other kind. Drill goals RESUMED from the
 * persisted slot between ticks do NOT increment — only freshly-picked
 * ones do, so a single long drill run on one field tile counts as
 * one streak unit, not one per tick.
 *
 * tinyint unsigned is plenty: typical thresholds live in the 3–10
 * range and 255 would represent ~20 hours of uninterrupted drilling
 * at the 5-min tick cadence, well past any reasonable diversification
 * budget.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->unsignedTinyInteger('bot_consecutive_drill_count')
                ->default(0)
                ->after('bot_goal_fail_count');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn('bot_consecutive_drill_count');
        });
    }
};
