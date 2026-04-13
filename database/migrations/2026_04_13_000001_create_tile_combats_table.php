<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spontaneous wasteland duels between two players (or a player + bot)
 * standing on the same wasteland tile.
 *
 * One row per resolved engagement. Populated by TileCombatService.
 * The composite indexes service the per-participant per-tile 24h
 * cooldown lookup — each participant earns a "no combat on this tile"
 * block as either attacker OR defender, so both directions need to
 * be covered by covering indexes.
 *
 * `oil_stolen` is whole barrels (Player.oil_barrels is unsigned int);
 * rounding is floor() inside the service to keep zero-loot fights
 * auditable without fractional barrels leaking into the economy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tile_combats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attacker_player_id')->constrained('players')->cascadeOnDelete();
            $table->foreignId('defender_player_id')->constrained('players')->cascadeOnDelete();
            $table->foreignId('tile_id')->constrained('tiles')->cascadeOnDelete();
            $table->enum('outcome', ['attacker_win', 'defender_win']);
            $table->unsignedInteger('oil_stolen')->default(0);
            $table->decimal('final_score', 6, 4);
            $table->unsignedBigInteger('rng_seed')->nullable();
            $table->string('rng_output', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Per-participant per-tile cooldown lookups — one composite
            // per side so the WHERE clause hits a covering index in
            // either direction.
            $table->index(['attacker_player_id', 'tile_id', 'created_at'], 'tc_attacker_tile_idx');
            $table->index(['defender_player_id', 'tile_id', 'created_at'], 'tc_defender_tile_idx');

            // "Recent activity on this tile" for the broadcast-only
            // edge case and for any future observation log.
            $table->index(['tile_id', 'created_at'], 'tc_tile_created_idx');

            // Attacker/defender side feeds in AttackLogService.
            $table->index(['defender_player_id', 'created_at'], 'tc_defender_created_idx');
            $table->index(['attacker_player_id', 'created_at'], 'tc_attacker_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tile_combats');
    }
};
