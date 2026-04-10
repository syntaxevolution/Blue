<?php

namespace Database\Seeders;

use App\Models\Item;
use Illuminate\Database\Seeder;

/**
 * Seeds items_catalog with the Phase 2 MVP shop inventory.
 *
 * Every item here applies its effects immediately on purchase — there
 * is no per-player inventory for consumables yet. Prices are barrel-only
 * to keep the economic loop simple: drill → sell is implicit (barrels are
 * the upgrade currency).
 *
 * Run via:
 *   php artisan db:seed --class=ItemsCatalogSeeder
 * Or as part of:
 *   php artisan db:seed
 *
 * Idempotent via updateOrCreate so you can re-run without duplication.
 */
class ItemsCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            // Strength post — melee weapons, +strength
            ['key' => 'small_rock',   'post_type' => 'strength', 'name' => 'Small Rock',   'description' => 'A fist-sized chunk of granite. Better than nothing.',               'price_barrels' => 5,   'effects' => ['stat_add' => ['strength' => 1]], 'sort_order' => 10],
            ['key' => 'boulder',      'post_type' => 'strength', 'name' => 'Boulder',      'description' => 'Two-handed, bone-crushing, inelegant.',                              'price_barrels' => 15,  'effects' => ['stat_add' => ['strength' => 2]], 'sort_order' => 20],
            ['key' => 'blackjack',    'post_type' => 'strength', 'name' => 'Blackjack',    'description' => 'Leather-wrapped lead weight. Quick, quiet, effective.',              'price_barrels' => 35,  'effects' => ['stat_add' => ['strength' => 3]], 'sort_order' => 30],
            ['key' => 'crowbar',      'post_type' => 'strength', 'name' => 'Crowbar',      'description' => 'Equally useful for prying open crates and opponents.',               'price_barrels' => 70,  'effects' => ['stat_add' => ['strength' => 4]], 'sort_order' => 40],

            // Stealth post — +stealth
            ['key' => 'boots',        'post_type' => 'stealth',  'name' => 'Boots',        'description' => 'Soft soles. Muffled steps.',                                         'price_barrels' => 5,   'effects' => ['stat_add' => ['stealth' => 1]],  'sort_order' => 10],
            ['key' => 'sneakers',     'post_type' => 'stealth',  'name' => 'Dust Sneakers','description' => 'Rubber tread, wrapped in cloth to kill the sound.',                  'price_barrels' => 15,  'effects' => ['stat_add' => ['stealth' => 2]],  'sort_order' => 20],
            ['key' => 'silent_steps', 'post_type' => 'stealth',  'name' => 'Silent Steps', 'description' => 'Gyroscopic boots that read the ground before your foot touches it.', 'price_barrels' => 35,  'effects' => ['stat_add' => ['stealth' => 3]],  'sort_order' => 30],
            ['key' => 'ghost_cloak',  'post_type' => 'stealth',  'name' => 'Ghost Cloak',  'description' => 'Dust-colored mesh that dissolves your outline at distance.',         'price_barrels' => 70,  'effects' => ['stat_add' => ['stealth' => 4]],  'sort_order' => 40],

            // Fort post — +fortification (defensive hardware) AND +security (counter-intel)
            ['key' => 'door_latch',     'post_type' => 'fort', 'name' => 'Door Latch',          'description' => 'Slows a raider down. Gives you a heartbeat to react.',       'price_barrels' => 5,   'effects' => ['stat_add' => ['fortification' => 1]], 'sort_order' => 10],
            ['key' => 'simple_lock',    'post_type' => 'fort', 'name' => 'Simple Lock',         'description' => 'Brass, handmade. Picks are expensive around here.',          'price_barrels' => 15,  'effects' => ['stat_add' => ['fortification' => 2]], 'sort_order' => 20],
            ['key' => 'reinforced_door','post_type' => 'fort', 'name' => 'Reinforced Door',     'description' => 'Steel plate over hardwood. Hinges welded.',                  'price_barrels' => 35,  'effects' => ['stat_add' => ['fortification' => 3]], 'sort_order' => 30],
            ['key' => 'guardbot',       'post_type' => 'fort', 'name' => 'Guardbot',            'description' => 'Tracked drone, salvage-built. Pattern patrols at night.',    'price_barrels' => 70,  'effects' => ['stat_add' => ['fortification' => 4]], 'sort_order' => 40],
            ['key' => 'trip_wire',      'post_type' => 'fort', 'name' => 'Trip Wire',           'description' => 'Announces any visitor whether they wanted to be announced.', 'price_barrels' => 5,   'effects' => ['stat_add' => ['security' => 1]],      'sort_order' => 50],
            ['key' => 'camera_net',     'post_type' => 'fort', 'name' => 'Camera Net',          'description' => 'Three cheap optics wired to a scrap monitor.',               'price_barrels' => 15,  'effects' => ['stat_add' => ['security' => 2]],      'sort_order' => 60],
            ['key' => 'counter_intel',  'post_type' => 'fort', 'name' => 'Counter-Intel Module','description' => 'Feeds false data to anyone spying on you. Expensive. Worth it.', 'price_barrels' => 35, 'effects' => ['stat_add' => ['security' => 3]],      'sort_order' => 70],

            // Tech post — drill tier upgrades
            ['key' => 'shovel_rig',     'post_type' => 'tech', 'name' => 'Shovel Rig',     'description' => 'A step up from the Dentist Drill. Fewer dry holes.',             'price_barrels' => 30,  'effects' => ['set_drill_tier' => 2], 'sort_order' => 10],
            ['key' => 'medium_drill',   'post_type' => 'tech', 'name' => 'Medium Drill',   'description' => 'Tracked, gas-powered. Noisy, effective.',                        'price_barrels' => 80,  'effects' => ['set_drill_tier' => 3], 'sort_order' => 20],
            ['key' => 'heavy_drill',    'post_type' => 'tech', 'name' => 'Heavy Drill',    'description' => 'The big rig. Mounts on a flatbed. Mostly reliable.',             'price_barrels' => 180, 'effects' => ['set_drill_tier' => 4], 'sort_order' => 30],
            ['key' => 'industrial_rig', 'post_type' => 'tech', 'name' => 'Industrial Rig', 'description' => 'No more dry points. The ground either gives up or breaks.',      'price_barrels' => 400, 'effects' => ['set_drill_tier' => 5], 'sort_order' => 40],
            ['key' => 'refinery',       'post_type' => 'tech', 'name' => 'Refinery',       'description' => 'Small on-site cracking plant. Guarantees at least one good well per field.', 'price_barrels' => 900, 'effects' => ['set_drill_tier' => 6], 'sort_order' => 50],

            // General store
            ['key' => 'explorers_atlas', 'post_type' => 'general', 'name' => "Explorer's Atlas", 'description' => "A leather-bound notebook of every tile you've ever stood on. Unlocks the atlas view from the nav bar — see your journey drawn on a grid.", 'price_barrels' => 30, 'effects' => ['unlocks' => ['atlas']], 'sort_order' => 10],
        ];

        foreach ($items as $data) {
            Item::updateOrCreate(['key' => $data['key']], $data);
        }
    }
}
