<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data migration: insert the two loot-crate deployable items into an
 * existing items_catalog so environments that ran an older seeder
 * pick up the new sabotage crates without a re-seed.
 *
 * Idempotent — uses updateOrInsert so re-running the migration on a
 * DB that already has these rows (e.g. a fresh install where the
 * seeder ran first) is a no-op.
 *
 * Prices read from `config/game.php` via the Laravel config helper
 * (no GameConfigResolver / service container — migrations should
 * stay self-contained and bootable before the full app is wired).
 * The fallback ints below match the spec defaults so a fresh install
 * with no DB overrides still gets the right prices.
 */
return new class extends Migration
{
    public function up(): void
    {
        $oilCost = (int) config('game.loot.items.siphon_oil.price_barrels', 500);
        $cashCost = (int) config('game.loot.items.siphon_cash.price_barrels', 10000);

        $now = now();

        $rows = [
            [
                'key' => 'crate_siphon_oil',
                'post_type' => 'general',
                'name' => 'Oil Siphon Crate',
                'description' => 'A sealed crate rigged to siphon barrels from whoever cracks it open. Plant it on a wasteland tile and hope a rival gets curious. Does nothing to the player who placed it.',
                'price_barrels' => $oilCost,
                'price_cash' => 0,
                'price_intel' => 0,
                'effects' => json_encode(['deployable_loot_crate' => ['kind' => 'oil']]),
                'sort_order' => 440,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'crate_siphon_cash',
                'post_type' => 'general',
                'name' => 'Cash Siphon Crate',
                'description' => 'A sealed crate wired to skim cash from whoever cracks it open. Heavier bait, deeper pockets. Costs more, steals more.',
                'price_barrels' => $cashCost,
                'price_cash' => 0,
                'price_intel' => 0,
                'effects' => json_encode(['deployable_loot_crate' => ['kind' => 'cash']]),
                'sort_order' => 450,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($rows as $row) {
            DB::table('items_catalog')->updateOrInsert(
                ['key' => $row['key']],
                $row,
            );
        }
    }

    public function down(): void
    {
        DB::table('items_catalog')
            ->whereIn('key', ['crate_siphon_oil', 'crate_siphon_cash'])
            ->delete();
    }
};
