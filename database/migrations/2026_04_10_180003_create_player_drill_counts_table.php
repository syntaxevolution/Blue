<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-player, per-oil-field, per-day drill tally.
 *
 * DrillService looks up (or inserts) the row for (player, field, today)
 * on every drill action and rejects the drill if the count has already
 * hit drilling.daily_limit_per_field. The reset is implicit: tomorrow's
 * drill_date won't match any existing row, so the query naturally returns
 * zero and the player starts fresh at midnight server time.
 *
 * Old rows are harmless but a nightly cleanup job can prune them if the
 * table grows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_drill_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained('players')->cascadeOnDelete();
            $table->foreignId('oil_field_id')->constrained('oil_fields')->cascadeOnDelete();
            $table->date('drill_date');
            $table->unsignedInteger('drill_count')->default(0);
            $table->timestamps();

            $table->unique(['player_id', 'oil_field_id', 'drill_date']);
            $table->index('drill_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_drill_counts');
    }
};
