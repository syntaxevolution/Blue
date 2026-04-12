<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill the four sabotage / counter-measure items with their
 * authoritative prices.
 *
 * Why a migration and not just a seeder re-run:
 *   - The initial ItemsCatalogSeeder run on the server happened with
 *     a stale config cache (or a timing mismatch during deploy), so
 *     the items_catalog rows landed with price_barrels = 0. Players
 *     could walk into the General Store and buy Gremlin Coils /
 *     Siphon Charges / Tripwire Wards / Deep Scanners for free.
 *   - A seeder re-run is a manual step that's easy to forget. A data
 *     migration is self-applying on the next `php artisan migrate`,
 *     and idempotent on fresh installs (the updateWhere has no effect
 *     if the rows don't exist yet — the seeder will handle those).
 *
 * Defensive: reads the live config values so changing them in
 * config/game.php later won't require rewriting this file. If a key
 * is missing at migrate time the update is skipped for that item
 * rather than wiping it to zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        $items = [
            'gremlin_coil' => 'items.gremlin_coil.price_barrels',
            'siphon_charge' => 'items.siphon_charge.price_barrels',
            'tripwire_ward' => 'items.tripwire_ward.price_barrels',
            'deep_scanner' => 'items.deep_scanner.price_barrels',
        ];

        foreach ($items as $key => $configKey) {
            $price = config($configKey);
            if (! is_numeric($price) || (int) $price <= 0) {
                // Missing or obviously wrong — don't clobber.
                continue;
            }

            DB::table('items_catalog')
                ->where('key', $key)
                ->update(['price_barrels' => (int) $price]);
        }
    }

    public function down(): void
    {
        // No-op. We don't want `migrate:rollback` to zero the prices out
        // again — that was the bug this migration fixes.
    }
};
