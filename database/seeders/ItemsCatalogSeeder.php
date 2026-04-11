<?php

namespace Database\Seeders;

use App\Domain\Config\GameConfigResolver;
use App\Models\Item;
use Illuminate\Database\Seeder;

/**
 * Seeds items_catalog with the Phase 2 MVP shop inventory plus the
 * Batch 1 expansion (5 new per store + 5 transport modes + teleporter
 * + extra-moves pack in the general store).
 *
 * Prices for transport, teleporter, and extra moves are pulled from
 * config/game.php so tuning those blocks in the admin panel stays the
 * single source of truth.
 *
 * Idempotent via updateOrCreate so you can re-run without duplication.
 */
class ItemsCatalogSeeder extends Seeder
{
    public function run(): void
    {
        /** @var GameConfigResolver $config */
        $config = app(GameConfigResolver::class);

        $transportCosts = (array) $config->get('general_store.transport');
        $transportCost = fn (string $k): int => (int) ($transportCosts[$k]['cost_barrels'] ?? 0);
        $teleporterCost = (int) $config->get('teleport.purchase_cost_barrels');
        $extraMovesCost = (int) $config->get('general_store.extra_moves.cost_barrels');

        $items = [
            /* ------------------------------------------------------------ */
            /* Strength post — melee weapons, +strength                      */
            /* ------------------------------------------------------------ */
            ['key' => 'small_rock',       'post_type' => 'strength', 'name' => 'Small Rock',       'description' => 'A fist-sized chunk of granite. Better than nothing.',                       'price_barrels' => 5,    'effects' => ['stat_add' => ['strength' => 1]],  'sort_order' => 10],
            ['key' => 'boulder',          'post_type' => 'strength', 'name' => 'Boulder',          'description' => 'Two-handed, bone-crushing, inelegant.',                                      'price_barrels' => 15,   'effects' => ['stat_add' => ['strength' => 2]],  'sort_order' => 20],
            ['key' => 'blackjack',        'post_type' => 'strength', 'name' => 'Blackjack',        'description' => 'Leather-wrapped lead weight. Quick, quiet, effective.',                      'price_barrels' => 35,   'effects' => ['stat_add' => ['strength' => 3]],  'sort_order' => 30],
            ['key' => 'crowbar',          'post_type' => 'strength', 'name' => 'Crowbar',          'description' => 'Equally useful for prying open crates and opponents.',                       'price_barrels' => 70,   'effects' => ['stat_add' => ['strength' => 4]],  'sort_order' => 40],
            // Batch 1 additions (5)
            ['key' => 'rusty_chain',      'post_type' => 'strength', 'name' => 'Rusty Chain',      'description' => 'Previously attached to a bicycle. Now it is a weapon.',                      'price_barrels' => 140,  'effects' => ['stat_add' => ['strength' => 5]],  'sort_order' => 50],
            ['key' => 'sledgehammer',     'post_type' => 'strength', 'name' => 'Sledgehammer',     'description' => 'Measures damage in units of "ow".',                                          'price_barrels' => 250,  'effects' => ['stat_add' => ['strength' => 6]],  'sort_order' => 60],
            ['key' => 'lead_pipe',        'post_type' => 'strength', 'name' => 'Lead Pipe',        'description' => 'Clue™ not included.',                                                        'price_barrels' => 420,  'effects' => ['stat_add' => ['strength' => 7]],  'sort_order' => 70],
            ['key' => 'spiked_gauntlet',  'post_type' => 'strength', 'name' => 'Spiked Gauntlet',  'description' => 'Only has one setting: aggressive.',                                          'price_barrels' => 700,  'effects' => ['stat_add' => ['strength' => 8]],  'sort_order' => 80],
            ['key' => 'surplus_minigun',  'post_type' => 'strength', 'name' => 'Surplus Minigun',  'description' => 'Found in a crate labelled "PLEASE RETURN". No return address.',              'price_barrels' => 1400, 'effects' => ['stat_add' => ['strength' => 10]], 'sort_order' => 90],

            /* ------------------------------------------------------------ */
            /* Stealth post — +stealth                                        */
            /* ------------------------------------------------------------ */
            ['key' => 'boots',            'post_type' => 'stealth',  'name' => 'Boots',             'description' => 'Soft soles. Muffled steps.',                                              'price_barrels' => 5,    'effects' => ['stat_add' => ['stealth' => 1]],  'sort_order' => 10],
            ['key' => 'sneakers',         'post_type' => 'stealth',  'name' => 'Dust Sneakers',     'description' => 'Rubber tread, wrapped in cloth to kill the sound.',                       'price_barrels' => 15,   'effects' => ['stat_add' => ['stealth' => 2]],  'sort_order' => 20],
            ['key' => 'silent_steps',     'post_type' => 'stealth',  'name' => 'Silent Steps',      'description' => 'Gyroscopic boots that read the ground before your foot touches it.',      'price_barrels' => 35,   'effects' => ['stat_add' => ['stealth' => 3]],  'sort_order' => 30],
            ['key' => 'ghost_cloak',      'post_type' => 'stealth',  'name' => 'Ghost Cloak',       'description' => 'Dust-coloured mesh that dissolves your outline at distance.',             'price_barrels' => 70,   'effects' => ['stat_add' => ['stealth' => 4]],  'sort_order' => 40],
            // Batch 1 additions (5)
            ['key' => 'scented_oils',     'post_type' => 'stealth',  'name' => 'Scented Oils',      'description' => 'You smell like the desert. The desert smells like nothing.',              'price_barrels' => 140,  'effects' => ['stat_add' => ['stealth' => 5]],  'sort_order' => 50],
            ['key' => 'whisper_boots',    'post_type' => 'stealth',  'name' => 'Whisper Boots',     'description' => 'Silent. Also slightly too tight.',                                        'price_barrels' => 250,  'effects' => ['stat_add' => ['stealth' => 6]],  'sort_order' => 60],
            ['key' => 'camo_tarp',        'post_type' => 'stealth',  'name' => 'Camo Tarp',         'description' => 'Works great unless you are moving.',                                      'price_barrels' => 420,  'effects' => ['stat_add' => ['stealth' => 7]],  'sort_order' => 70],
            ['key' => 'distraction_duck', 'post_type' => 'stealth',  'name' => 'Distraction Duck',  'description' => 'Guards watch it. You watch them watch it.',                                'price_barrels' => 700,  'effects' => ['stat_add' => ['stealth' => 8]],  'sort_order' => 80],
            ['key' => 'void_cowl',        'post_type' => 'stealth',  'name' => 'Void Cowl',         'description' => 'Makes you slightly less visible. Also makes you mildly sad.',              'price_barrels' => 1400, 'effects' => ['stat_add' => ['stealth' => 10]], 'sort_order' => 90],

            /* ------------------------------------------------------------ */
            /* Fort post — +fortification (defensive) + +security (counter-intel) */
            /* ------------------------------------------------------------ */
            ['key' => 'door_latch',         'post_type' => 'fort', 'name' => 'Door Latch',              'description' => 'Slows a raider down. Gives you a heartbeat to react.',              'price_barrels' => 5,    'effects' => ['stat_add' => ['fortification' => 1]], 'sort_order' => 10],
            ['key' => 'simple_lock',        'post_type' => 'fort', 'name' => 'Simple Lock',             'description' => 'Brass, handmade. Picks are expensive around here.',                 'price_barrels' => 15,   'effects' => ['stat_add' => ['fortification' => 2]], 'sort_order' => 20],
            ['key' => 'reinforced_door',    'post_type' => 'fort', 'name' => 'Reinforced Door',         'description' => 'Steel plate over hardwood. Hinges welded.',                         'price_barrels' => 35,   'effects' => ['stat_add' => ['fortification' => 3]], 'sort_order' => 30],
            ['key' => 'guardbot',           'post_type' => 'fort', 'name' => 'Guardbot',                'description' => 'Tracked drone, salvage-built. Pattern patrols at night.',            'price_barrels' => 70,   'effects' => ['stat_add' => ['fortification' => 4]], 'sort_order' => 40],
            ['key' => 'trip_wire',          'post_type' => 'fort', 'name' => 'Trip Wire',               'description' => 'Announces any visitor whether they wanted to be announced.',         'price_barrels' => 5,    'effects' => ['stat_add' => ['security' => 1]],      'sort_order' => 50],
            ['key' => 'camera_net',         'post_type' => 'fort', 'name' => 'Camera Net',              'description' => 'Three cheap optics wired to a scrap monitor.',                       'price_barrels' => 15,   'effects' => ['stat_add' => ['security' => 2]],      'sort_order' => 60],
            ['key' => 'counter_intel',      'post_type' => 'fort', 'name' => 'Counter-Intel Module',    'description' => 'Feeds false data to anyone spying on you. Expensive. Worth it.',    'price_barrels' => 35,   'effects' => ['stat_add' => ['security' => 3]],      'sort_order' => 70],
            // Batch 1 additions (5 — mix of fort + security)
            ['key' => 'sandbag_wall',       'post_type' => 'fort', 'name' => 'Sandbag Wall',            'description' => 'Pre-filled. Mostly with sand.',                                      'price_barrels' => 140,  'effects' => ['stat_add' => ['fortification' => 5]], 'sort_order' => 80],
            ['key' => 'concrete_moat',      'post_type' => 'fort', 'name' => 'Concrete Moat',           'description' => 'Water optional.',                                                    'price_barrels' => 250,  'effects' => ['stat_add' => ['fortification' => 6]], 'sort_order' => 90],
            ['key' => 'motion_sensor_array','post_type' => 'fort', 'name' => 'Motion Sensor Array',     'description' => 'Triggers on squirrels. And wind. And squirrels.',                    'price_barrels' => 180,  'effects' => ['stat_add' => ['security' => 4]],      'sort_order' => 100],
            ['key' => 'laser_grid',         'post_type' => 'fort', 'name' => 'Laser Grid',              'description' => 'Illegally upgraded from a supermarket produce mister.',              'price_barrels' => 420,  'effects' => ['stat_add' => ['fortification' => 7]], 'sort_order' => 110],
            ['key' => 'autocannon_turret',  'post_type' => 'fort', 'name' => 'Autocannon Turret',       'description' => 'Has a customer-service hotline. Do not call it.',                    'price_barrels' => 900,  'effects' => ['stat_add' => ['fortification' => 8]], 'sort_order' => 120],

            /* ------------------------------------------------------------ */
            /* Tech post — drill tier upgrades + utility                      */
            /* ------------------------------------------------------------ */
            ['key' => 'shovel_rig',           'post_type' => 'tech', 'name' => 'Shovel Rig',          'description' => 'A step up from the Dentist Drill. Fewer dry holes.',                                'price_barrels' => 30,   'effects' => ['set_drill_tier' => 2], 'sort_order' => 10],
            ['key' => 'medium_drill',         'post_type' => 'tech', 'name' => 'Medium Drill',        'description' => 'Tracked, gas-powered. Noisy, effective.',                                           'price_barrels' => 80,   'effects' => ['set_drill_tier' => 3], 'sort_order' => 20],
            ['key' => 'heavy_drill',          'post_type' => 'tech', 'name' => 'Heavy Drill',         'description' => 'The big rig. Mounts on a flatbed. Mostly reliable.',                                'price_barrels' => 180,  'effects' => ['set_drill_tier' => 4], 'sort_order' => 30],
            ['key' => 'industrial_rig',       'post_type' => 'tech', 'name' => 'Industrial Rig',      'description' => 'No more dry points. The ground either gives up or breaks.',                         'price_barrels' => 400,  'effects' => ['set_drill_tier' => 5], 'sort_order' => 40],
            ['key' => 'refinery',             'post_type' => 'tech', 'name' => 'Refinery',            'description' => 'Small on-site cracking plant. Guarantees at least one good well per field.',       'price_barrels' => 900,  'effects' => ['set_drill_tier' => 6], 'sort_order' => 50],
            // Batch 1 additions — 5 passive / unlock tech items (no unimplemented consumables)
            ['key' => 'oil_diviner',          'post_type' => 'tech', 'name' => 'Oil Diviner',         'description' => 'A stick. A very confident stick. Unlocks quality preview on drill points.',         'price_barrels' => 500,  'effects' => ['unlocks' => ['drill_quality_preview']], 'sort_order' => 60],
            ['key' => 'field_journal',        'post_type' => 'tech', 'name' => 'Field Journal',       'description' => 'Tidy notes, tidy yields. +1 daily drill per oil field.',                            'price_barrels' => 300,  'effects' => ['daily_drill_limit_bonus' => 1],   'sort_order' => 70],
            ['key' => 'lucky_coin',           'post_type' => 'tech', 'name' => 'Lucky Coin',          'description' => 'Tarnished. Lucky. −0.5% drill break chance.',                                       'price_barrels' => 400,  'effects' => ['break_chance_reduction_pct' => 0.005], 'sort_order' => 80],
            ['key' => 'torque_wrench',        'post_type' => 'tech', 'name' => 'Torque Wrench',       'description' => 'Reduces drill break chance by another 0.3%.',                                       'price_barrels' => 600,  'effects' => ['break_chance_reduction_pct' => 0.003], 'sort_order' => 90],
            ['key' => 'seismic_scanner',      'post_type' => 'tech', 'name' => 'Seismic Scanner',     'description' => '+2% drill yield on every pull. The hum is soothing.',                               'price_barrels' => 700,  'effects' => ['drill_yield_bonus_pct' => 0.02],  'sort_order' => 100],

            /* ------------------------------------------------------------ */
            /* General store — utility + transport + teleporter + extra moves */
            /* ------------------------------------------------------------ */
            ['key' => 'explorers_atlas',   'post_type' => 'general', 'name' => "Explorer's Atlas",    'description' => "A leather-bound notebook of every tile you've ever stood on. Unlocks the atlas view from the nav bar — see your journey drawn on a grid.", 'price_barrels' => 30, 'effects' => ['unlocks' => ['atlas']], 'sort_order' => 10],
            // Batch 1 additions — passive / consumable items with implemented effects
            ['key' => 'emergency_ration',  'post_type' => 'general', 'name' => 'Emergency Ration',    'description' => 'Tastes like the inside of a filing cabinet. +20 moves immediately.',                                              'price_barrels' => 150, 'effects' => ['grant_moves' => 20],                         'sort_order' => 20],
            ['key' => 'compass_plus',      'post_type' => 'general', 'name' => 'Compass Plus',        'description' => 'Points north and at approximately two of its friends.',                                                            'price_barrels' => 600, 'effects' => ['unlocks' => ['wider_fog_radius']],           'sort_order' => 30],
            ['key' => 'lucky_charm',       'post_type' => 'general', 'name' => 'Lucky Charm',         'description' => 'Bone, twine, and one of your teeth (probably). +5% drill yield.',                                                'price_barrels' => 800, 'effects' => ['drill_yield_bonus_pct' => 0.05],             'sort_order' => 40],
            ['key' => 'iron_resolve',      'post_type' => 'general', 'name' => 'Iron Resolve',        'description' => 'A motivational pamphlet and a cracked coffee mug. +1 daily drill per field.',                                     'price_barrels' => 700, 'effects' => ['daily_drill_limit_bonus' => 1],              'sort_order' => 50],
            ['key' => 'lucky_rabbit_foot', 'post_type' => 'general', 'name' => 'Lucky Rabbit Foot',   'description' => 'Unlucky for the rabbit. −0.3% drill break chance.',                                                              'price_barrels' => 500, 'effects' => ['break_chance_reduction_pct' => 0.003],       'sort_order' => 60],

            // Extra moves — consumable, unlimited, overflows cap
            ['key' => 'extra_moves_pack',  'post_type' => 'general', 'name' => 'Extra Moves Pack',    'description' => 'A thermos of something highly caffeinated and mildly illegal. +10 moves.',                                       'price_barrels' => $extraMovesCost, 'effects' => ['grant_moves' => true], 'sort_order' => 100],

            // Transport modes — one-time purchase each, switchable any time
            ['key' => 'bicycle',           'post_type' => 'general', 'name' => 'Bicycle',             'description' => 'Rusted, squeaky, two wheels, zero fuel. Travels 2 tiles per press.',                                              'price_barrels' => $transportCost('bicycle'),    'effects' => ['unlocks_transport' => 'bicycle'],    'sort_order' => 200],
            ['key' => 'motorcycle',        'post_type' => 'general', 'name' => 'Motorcycle',          'description' => 'Held together with wire and prayer. 5 tiles per press, 1 barrel per trip.',                                       'price_barrels' => $transportCost('motorcycle'), 'effects' => ['unlocks_transport' => 'motorcycle'], 'sort_order' => 210],
            ['key' => 'sand_runner',       'post_type' => 'general', 'name' => 'Sand Runner',         'description' => 'Half dune buggy, half filing cabinet. 10 tiles per press, reveals the neighbours of wherever it parks.',        'price_barrels' => $transportCost('sand_runner'),'effects' => ['unlocks_transport' => 'sand_runner'],'sort_order' => 220],
            ['key' => 'helicopter',        'post_type' => 'general', 'name' => 'Helicopter',          'description' => 'Loud, thirsty, terrifying. 25 tiles per press.',                                                                 'price_barrels' => $transportCost('helicopter'), 'effects' => ['unlocks_transport' => 'helicopter'], 'sort_order' => 230],
            ['key' => 'airplane',          'post_type' => 'general', 'name' => 'Airplane',            'description' => 'Paint peeling but the engine still turns. 50 tiles per press — reveals every tile in the flight path.',        'price_barrels' => $transportCost('airplane'),   'effects' => ['unlocks_transport' => 'airplane'],   'sort_order' => 240],

            // Teleporter
            ['key' => 'teleporter',        'post_type' => 'general', 'name' => 'Teleporter',          'description' => 'Brass, filigree, vaguely biblical. Pay a small oil tribute each use.',                                             'price_barrels' => $teleporterCost, 'effects' => ['unlocks_teleport' => true], 'sort_order' => 300],
        ];

        foreach ($items as $data) {
            Item::updateOrCreate(['key' => $data['key']], $data);
        }
    }
}
