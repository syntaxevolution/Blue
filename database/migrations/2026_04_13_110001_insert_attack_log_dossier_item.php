<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Insert the Counter-Intel Dossier into items_catalog.
 *
 * The dossier was referenced all over the codebase
 * (AttackLogService, AttackLogController, the locked-state copy in
 * Game/AttackLog.vue) but the actual row was never seeded. Players
 * who followed the in-game hint "buy it at a Fortification Post"
 * couldn't find it because the shop query returned no matching item.
 *
 * This migration is self-applying on existing servers so the fix
 * ships on the next `php artisan migrate` without the usual seeder
 * re-run dance. Idempotent — uses updateOrInsert so running it twice
 * is a no-op. If a future ItemsCatalogSeeder run also inserts the
 * row, the updateOrInsert here overwrites it with the same values.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('items_catalog')->updateOrInsert(
            ['key' => 'attack_log_dossier'],
            [
                'post_type' => 'fort',
                'name' => 'Counter-Intel Dossier',
                'description' => 'A locked archive and a paid informant network. Every raid on your base — and every sabotage trigger — is logged with the attacker\'s name. Unlocks the Attack Log from the nav.',
                'price_barrels' => 400,
                'price_cash' => 0,
                'price_intel' => 0,
                'effects' => json_encode(['unlocks' => ['attack_log']]),
                'sort_order' => 75,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        // No-op. Removing the row would re-break /attack-log for every
        // player who already purchased it.
    }
};
