<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loot crates occupying one wasteland tile.
 *
 * A single row represents a crate physically sitting on a wasteland
 * tile. There are TWO kinds, distinguished by placed_by_player_id:
 *
 *   - Real crate    (placed_by_player_id NULL, device_key NULL) —
 *                   spontaneously spawned by the travel hook when a
 *                   player arrives on a wasteland tile and the spawn
 *                   RNG fires (default 1%). Reward rolled at open time.
 *
 *   - Sabotage crate (placed_by_player_id SET, device_key SET) —
 *                   deployed by a player from their toolbox. Siphons
 *                   oil or cash from the victim at open time and
 *                   credits the placer instantly.
 *
 * Persistence model: rows stay after trigger so the Hostility Log
 * (attack-log) and RNG audit trail can reconstruct what happened later.
 * "Active" crates are rows where opened_at IS NULL.
 *
 * Per-tile uniqueness (one unopened crate per wasteland tile) is
 * enforced at the application layer inside LootCrateService under a
 * row-locked transaction. Partial unique indexes aren't portable on
 * MariaDB, so we mirror the DrillPointSabotage pattern: service gate
 * plus surrounding transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tile_loot_crates', function (Blueprint $table) {
            $table->id();

            // Tile coordinates. We store (x, y) directly instead of a
            // tile_id FK because the world can regrow around us and
            // because the movement hook already has the Tile row in
            // scope — no need for an extra join.
            $table->integer('tile_x');
            $table->integer('tile_y');

            // NULL = real crate (spawned by the world). Set = sabotage
            // crate placed by a player. Nullable cascade on delete so
            // player account deletion doesn't orphan a tile slot.
            $table->foreignId('placed_by_player_id')
                ->nullable()
                ->constrained('players')
                ->nullOnDelete();

            // NULL for real crates, 'siphon_oil' or 'siphon_cash' for
            // sabotage crates. References items_catalog.key for the
            // corresponding toolbox item but is not a hard FK because
            // the catalog uses a string PK and future variants should
            // be pluggable without DB trigger cascades.
            $table->string('device_key', 64)->nullable();

            $table->timestamp('placed_at');

            // NULL while unopened. Set at open time whether the opener
            // got a reward, nothing, or was immune.
            $table->timestamp('opened_at')->nullable();
            $table->foreignId('opened_by_player_id')
                ->nullable()
                ->constrained('players')
                ->nullOnDelete();

            // Outcome payload written at open time. Shape varies by
            // crate kind:
            //   real:
            //     { kind: 'nothing'|'oil'|'cash'|'item'|'item_dupe',
            //       barrels?: int, cash?: float, item_key?: string }
            //   sabotage (non-immune):
            //     { kind: 'sabotage_oil'|'sabotage_cash',
            //       amount: int|float, victim_before: int|float,
            //       steal_pct: float }
            //   sabotage (immune victim):
            //     { kind: 'immune_no_effect' }
            $table->json('outcome')->nullable();

            $table->timestamps();

            // Fast "any unopened crate on this tile?" lookup. The
            // service-level uniqueness check runs this query under
            // lockForUpdate before inserting.
            $table->index(['tile_x', 'tile_y', 'opened_at'], 'tlc_tile_active_idx');

            // Fast "currently deployed count for a placer" — powers
            // the per-player deployment cap enforcement.
            $table->index(['placed_by_player_id', 'opened_at'], 'tlc_placer_active_idx');

            $table->index('opened_by_player_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tile_loot_crates');
    }
};
