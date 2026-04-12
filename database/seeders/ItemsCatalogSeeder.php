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
        $extraMovesAmount = (int) $config->get('general_store.extra_moves.amount');

        // Sabotage items live under items.* in config so tuning their cost
        // doesn't require re-running the seeder or a deploy — the shop
        // reads price_barrels from items_catalog, and the seeder is the
        // source of that mirror. Admins who want to re-price live should
        // update items_catalog directly (Filament) rather than touching
        // the config keys.
        $gremlinCoilCost = (int) $config->get('items.gremlin_coil.price_barrels');
        $siphonChargeCost = (int) $config->get('items.siphon_charge.price_barrels');
        $tripwireWardCost = (int) $config->get('items.tripwire_ward.price_barrels');
        $deepScannerCost = (int) $config->get('items.deep_scanner.price_barrels');

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
            // Batch 2 — high-end strength (significant price jumps)
            ['key' => 'tempest_hammer',   'post_type' => 'strength', 'name' => 'Tempest Hammer',   'description' => 'A warhammer wrapped in copper wire. Hums on windy days. Worryingly so.',     'price_barrels' => 3000,  'effects' => ['stat_add' => ['strength' => 11]], 'sort_order' => 100],
            ['key' => 'mono_saber',       'post_type' => 'strength', 'name' => 'Monomolecular Saber', 'description' => 'The edge is one atom thick. Do not touch it. Actually, do not look at it.', 'price_barrels' => 6500,  'effects' => ['stat_add' => ['strength' => 12]], 'sort_order' => 110],
            ['key' => 'railgun_sidearm',  'post_type' => 'strength', 'name' => 'Railgun Sidearm',  'description' => 'Fires iron slugs at unhealthy speeds. Battery pack not included.',            'price_barrels' => 14000, 'effects' => ['stat_add' => ['strength' => 13]], 'sort_order' => 120],
            ['key' => 'warbeast_harness', 'post_type' => 'strength', 'name' => 'Warbeast Harness', 'description' => 'Wearable exo-frame. Doubles your lifts. Triples your spinal compression.',   'price_barrels' => 30000, 'effects' => ['stat_add' => ['strength' => 14]], 'sort_order' => 130],
            ['key' => 'sovereign_fist',   'post_type' => 'strength', 'name' => "Sovereign's Fist", 'description' => 'A ceremonial gauntlet. Whoever wears it gets listened to.',                   'price_barrels' => 65000, 'effects' => ['stat_add' => ['strength' => 15]], 'sort_order' => 140],

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
            // Batch 2 — high-end stealth (significant price jumps)
            ['key' => 'mirror_shroud',    'post_type' => 'stealth',  'name' => 'Mirror Shroud',     'description' => 'Reflects the desert back at itself. Works best when nobody is looking.',   'price_barrels' => 3000,  'effects' => ['stat_add' => ['stealth' => 11]], 'sort_order' => 100],
            ['key' => 'silent_protocol', 'post_type' => 'stealth',   'name' => 'Silent Protocol',   'description' => 'Wearable signal dampener. Your footprints log out.',                       'price_barrels' => 6500,  'effects' => ['stat_add' => ['stealth' => 12]], 'sort_order' => 110],
            ['key' => 'phantom_weave',   'post_type' => 'stealth',   'name' => 'Phantom Weave',     'description' => 'Bedsheet-thin bodysuit. Cold, clingy, nearly invisible in low light.',     'price_barrels' => 14000, 'effects' => ['stat_add' => ['stealth' => 13]], 'sort_order' => 120],
            ['key' => 'ghostline_cloak', 'post_type' => 'stealth',   'name' => 'Ghostline Cloak',   'description' => 'Seam-stitched from old camouflage and a lot of regret.',                   'price_barrels' => 30000, 'effects' => ['stat_add' => ['stealth' => 14]], 'sort_order' => 130],
            ['key' => 'null_signature',  'post_type' => 'stealth',   'name' => 'Null Signature',    'description' => 'A full kit that erases your thermal, audio, and psychic presence.',        'price_barrels' => 65000, 'effects' => ['stat_add' => ['stealth' => 15]], 'sort_order' => 140],

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
            // Batch 2 — high-end fort (3 fortification + 2 security)
            ['key' => 'reinforced_bunker',  'post_type' => 'fort', 'name' => 'Reinforced Bunker',       'description' => 'Sunk concrete, steel rebar, emergency canned peaches.',               'price_barrels' => 2500,  'effects' => ['stat_add' => ['fortification' => 9]],  'sort_order' => 130],
            ['key' => 'bastion_gate',       'post_type' => 'fort', 'name' => 'Bastion Gate',            'description' => 'Two tonnes of slabbed steel. Hinges grease themselves.',              'price_barrels' => 6000,  'effects' => ['stat_add' => ['fortification' => 10]], 'sort_order' => 140],
            ['key' => 'fortress_protocol',  'post_type' => 'fort', 'name' => 'Fortress Protocol',       'description' => 'Automated lockdown system. Traps raiders in your kitchen for hours.', 'price_barrels' => 18000, 'effects' => ['stat_add' => ['fortification' => 12]], 'sort_order' => 150],
            ['key' => 'signal_disruptor',   'post_type' => 'fort', 'name' => 'Signal Disruptor',        'description' => 'Broadcasts elevator music on every reconnaissance frequency.',        'price_barrels' => 3000,  'effects' => ['stat_add' => ['security' => 5]],       'sort_order' => 160],
            ['key' => 'counter_intel_array','post_type' => 'fort', 'name' => 'Counter-Intel Array',     'description' => 'Feeds spies a plausible but wrong inventory. Includes a decoy gerbil.', 'price_barrels' => 9000,  'effects' => ['stat_add' => ['security' => 6]],       'sort_order' => 170],

            /* ------------------------------------------------------------ */
            /* Tech post — drill tier upgrades + utility                      */
            /* ------------------------------------------------------------ */
            ['key' => 'shovel_rig',           'post_type' => 'tech', 'name' => 'Shovel Rig',          'description' => 'A step up from the Dentist Drill. Fewer dry holes.',                                'price_barrels' => 200,   'effects' => ['set_drill_tier' => 2], 'sort_order' => 10],
            ['key' => 'medium_drill',         'post_type' => 'tech', 'name' => 'Medium Drill',        'description' => 'Tracked, gas-powered. Noisy, effective.',                                           'price_barrels' => 900,   'effects' => ['set_drill_tier' => 3], 'sort_order' => 20],
            ['key' => 'heavy_drill',          'post_type' => 'tech', 'name' => 'Heavy Drill',         'description' => 'The big rig. Mounts on a flatbed. Mostly reliable.',                                'price_barrels' => 3500,  'effects' => ['set_drill_tier' => 4], 'sort_order' => 30],
            ['key' => 'industrial_rig',       'post_type' => 'tech', 'name' => 'Industrial Rig',      'description' => 'No more dry points. The ground either gives up or breaks.',                         'price_barrels' => 12000, 'effects' => ['set_drill_tier' => 5], 'sort_order' => 40],
            ['key' => 'refinery',             'post_type' => 'tech', 'name' => 'Refinery',            'description' => 'Small on-site cracking plant. Guarantees at least one good well per field.',       'price_barrels' => 40000, 'effects' => ['set_drill_tier' => 6], 'sort_order' => 50],
            // Batch 1 additions — 5 passive tech items, all with working handlers
            ['key' => 'field_journal',        'post_type' => 'tech', 'name' => 'Field Journal',       'description' => 'Tidy notes, tidy yields. +1 daily drill per oil field.',                            'price_barrels' => 300,  'effects' => ['daily_drill_limit_bonus' => 1],   'sort_order' => 60],
            ['key' => 'lucky_coin',           'post_type' => 'tech', 'name' => 'Lucky Coin',          'description' => 'Tarnished. Lucky. −0.5% drill break chance.',                                       'price_barrels' => 400,  'effects' => ['break_chance_reduction_pct' => 0.005], 'sort_order' => 70],
            ['key' => 'torque_wrench',        'post_type' => 'tech', 'name' => 'Torque Wrench',       'description' => '−0.3% drill break chance. Smells faintly of diesel.',                               'price_barrels' => 600,  'effects' => ['break_chance_reduction_pct' => 0.003], 'sort_order' => 80],
            ['key' => 'seismic_scanner',      'post_type' => 'tech', 'name' => 'Seismic Scanner',     'description' => '+2% drill yield on every pull. The hum is soothing.',                               'price_barrels' => 700,  'effects' => ['drill_yield_bonus_pct' => 0.02],  'sort_order' => 90],
            ['key' => 'reinforced_bit',       'post_type' => 'tech', 'name' => 'Reinforced Bit',      'description' => '−0.4% drill break chance. Tungsten, mostly.',                                       'price_barrels' => 900,  'effects' => ['break_chance_reduction_pct' => 0.004], 'sort_order' => 100],
            // Batch 2 — high-end tech (all passive; stack additively via PassiveBonusService)
            ['key' => 'deep_core_mapper',     'post_type' => 'tech', 'name' => 'Deep Core Mapper',    'description' => '+3% drill yield. A chattering little box that whispers where the oil hides.',      'price_barrels' => 4000,  'effects' => ['drill_yield_bonus_pct' => 0.03],       'sort_order' => 110],
            ['key' => 'dual_shaft_mount',     'post_type' => 'tech', 'name' => 'Dual Shaft Mount',    'description' => 'Runs two bits off one engine. +1 daily drill per oil field.',                       'price_barrels' => 7500,  'effects' => ['daily_drill_limit_bonus' => 1],        'sort_order' => 120],
            ['key' => 'harmonic_dampener',    'post_type' => 'tech', 'name' => 'Harmonic Dampener',   'description' => '−0.6% drill break chance. Cancels the bad vibrations. Literally.',                 'price_barrels' => 12000, 'effects' => ['break_chance_reduction_pct' => 0.006], 'sort_order' => 130],
            ['key' => 'tungsten_core_bit',    'post_type' => 'tech', 'name' => 'Tungsten Core Bit',   'description' => '−0.8% drill break chance. Costs a fortune. Outlasts your grandchildren.',           'price_barrels' => 20000, 'effects' => ['break_chance_reduction_pct' => 0.008], 'sort_order' => 140],
            ['key' => 'prospectors_almanac',  'post_type' => 'tech', 'name' => "Prospector's Almanac",'description' => '+4% drill yield. Hand-written by someone who died rich and paranoid.',             'price_barrels' => 35000, 'effects' => ['drill_yield_bonus_pct' => 0.04],       'sort_order' => 150],

            /* ------------------------------------------------------------ */
            /* General store — utility + transport + teleporter + extra moves */
            /* ------------------------------------------------------------ */
            ['key' => 'explorers_atlas',   'post_type' => 'general', 'name' => "Explorer's Atlas",    'description' => "A leather-bound notebook of every tile you've ever stood on. Unlocks the atlas view from the nav bar — see your journey drawn on a grid.", 'price_barrels' => 30, 'effects' => ['unlocks' => ['atlas']], 'sort_order' => 10],
            // Batch 1 additions — 5 general items, every effect handled in code
            ['key' => 'emergency_ration',  'post_type' => 'general', 'name' => 'Emergency Ration',    'description' => 'Tastes like the inside of a filing cabinet. +20 moves immediately.',                                              'price_barrels' => 150, 'effects' => ['grant_moves' => 20],                         'sort_order' => 20],
            ['key' => 'iron_resolve',      'post_type' => 'general', 'name' => 'Iron Resolve',        'description' => 'A motivational pamphlet and a cracked coffee mug. +1 daily drill per field.',                                     'price_barrels' => 5000, 'effects' => ['daily_drill_limit_bonus' => 1],              'sort_order' => 50],
            ['key' => 'lucky_charm',       'post_type' => 'general', 'name' => 'Lucky Charm',         'description' => 'Bone, twine, and one of your teeth (probably). +5% drill yield.',                                                'price_barrels' => 4000, 'effects' => ['drill_yield_bonus_pct' => 0.05],             'sort_order' => 40],
            ['key' => 'lucky_rabbit_foot', 'post_type' => 'general', 'name' => 'Lucky Rabbit Foot',   'description' => 'Unlucky for the rabbit. −0.3% drill break chance.',                                                              'price_barrels' => 3000, 'effects' => ['break_chance_reduction_pct' => 0.003],       'sort_order' => 30],
            ['key' => 'caffeine_tin',      'post_type' => 'general', 'name' => 'Caffeine Tin',        'description' => 'Forty one small white tablets. Strictly rationed. +15 moves.',                                                   'price_barrels' => 120, 'effects' => ['grant_moves' => 15],                         'sort_order' => 60],

            // Extra moves — consumable, unlimited, overflows cap
            ['key' => 'extra_moves_pack',  'post_type' => 'general', 'name' => 'Extra Moves Pack',    'description' => "A thermos of something highly caffeinated and mildly illegal. +{$extraMovesAmount} moves in a single gulp.",     'price_barrels' => $extraMovesCost, 'effects' => ['grant_moves' => true], 'sort_order' => 100],

            // Bank cap bonus — permanent, stackable. Each copy adds +10 to
            // the player's move bank cap. Applied on-read via PassiveBonusService.
            ['key' => 'iron_lungs',        'post_type' => 'general', 'name' => 'Iron Lungs',          'description' => 'A breathing regimen that would kill a lesser wastelander. Permanently raises your maximum moves by 10. Stackable — buy as many as your chest can hold.', 'price_barrels' => 2500, 'effects' => ['bank_cap_bonus' => 10], 'sort_order' => 110],

            // Transport modes — one-time purchase each, switchable any time
            ['key' => 'bicycle',           'post_type' => 'general', 'name' => 'Bicycle',             'description' => 'Rusted, squeaky, two wheels, zero fuel. Travels 2 tiles per press.',                                              'price_barrels' => $transportCost('bicycle'),    'effects' => ['unlocks_transport' => 'bicycle'],    'sort_order' => 200],
            ['key' => 'motorcycle',        'post_type' => 'general', 'name' => 'Motorcycle',          'description' => 'Held together with wire and prayer. 5 tiles per press, 1 barrel per trip.',                                       'price_barrels' => $transportCost('motorcycle'), 'effects' => ['unlocks_transport' => 'motorcycle'], 'sort_order' => 210],
            ['key' => 'sand_runner',       'post_type' => 'general', 'name' => 'Sand Runner',         'description' => 'Half dune buggy, half filing cabinet. 10 tiles per press, reveals the neighbours of wherever it parks.',        'price_barrels' => $transportCost('sand_runner'),'effects' => ['unlocks_transport' => 'sand_runner'],'sort_order' => 220],
            ['key' => 'helicopter',        'post_type' => 'general', 'name' => 'Helicopter',          'description' => 'Loud, thirsty, terrifying. 25 tiles per press.',                                                                 'price_barrels' => $transportCost('helicopter'), 'effects' => ['unlocks_transport' => 'helicopter'], 'sort_order' => 230],
            ['key' => 'airplane',          'post_type' => 'general', 'name' => 'Airplane',            'description' => 'Paint peeling but the engine still turns. 50 tiles per press — reveals every tile in the flight path.',        'price_barrels' => $transportCost('airplane'),   'effects' => ['unlocks_transport' => 'airplane'],   'sort_order' => 240],

            // Teleporter
            ['key' => 'teleporter',        'post_type' => 'general', 'name' => 'Teleporter',          'description' => 'Brass, filigree, vaguely biblical. Pay a small oil tribute each use.',                                             'price_barrels' => $teleporterCost, 'effects' => ['unlocks_teleport' => true], 'sort_order' => 300],

            /* ------------------------------------------------------------ */
            /* General store — sabotage deployables + counter measures       */
            /* ------------------------------------------------------------ */
            // Stackable deployable consumables. Each plant decrements quantity
            // by 1 via SabotageService::place(); when quantity hits 0 the
            // player_items row is removed so the Toolbox HUD hides it.
            // Not in ShopService::SINGLE_PURCHASE_EFFECT_KEYS — buy as many
            // as you want, stack them in the Toolbox, plant whenever.
            ['key' => 'gremlin_coil',      'post_type' => 'general', 'name' => 'Gremlin Coil',        'description' => 'Plant it in a drill hole. The next rig that hits it wrecks itself. Does nothing to the player behind the wheel — only the hardware.',                                  'price_barrels' => $gremlinCoilCost,  'effects' => ['deployable_sabotage' => 'rig_wrecker'], 'sort_order' => 400],
            ['key' => 'siphon_charge',     'post_type' => 'general', 'name' => 'Siphon Charge',       'description' => 'A Gremlin Coil wired to a vacuum pump. Wrecks the rig AND steals half the victim\'s oil, piped straight back to you. Expensive for a reason.',                          'price_barrels' => $siphonChargeCost, 'effects' => ['deployable_sabotage' => 'siphon'],      'sort_order' => 410],

            // Counter: stackable consumable. Having ≥1 in your inventory is
            // passive coverage — a trap triggered on you consumes one
            // Tripwire Ward and leaves your rig untouched (you still lose
            // the move and get no barrels from that cell).
            ['key' => 'tripwire_ward',     'post_type' => 'general', 'name' => 'Tripwire Ward',       'description' => 'A handheld sensor that screams right before your rig touches something it shouldn\'t. Auto-consumes on contact to save your drill.',                                    'price_barrels' => $tripwireWardCost, 'effects' => ['counter_measure' => 'detector'],        'sort_order' => 420],

            // Counter: single-purchase, permanent, passive. When you stand
            // on an oil field, any active trap placed by someone else is
            // revealed on the drill grid and the square is click-blocked
            // client-side (server defensively rejects too). Flagged as
            // single-purchase via `unlocks` (it lives in ShopService's
            // SINGLE_PURCHASE_EFFECT_KEYS list already).
            ['key' => 'deep_scanner',      'post_type' => 'general', 'name' => 'Deep Scanner',        'description' => 'Seismic imaging rig strapped to your belt. Shows you which drill points on the current field are rigged to blow. Once owned, always on.',                           'price_barrels' => $deepScannerCost,  'effects' => ['unlocks' => ['sabotage_scanner']],      'sort_order' => 430],
        ];

        foreach ($items as $data) {
            Item::updateOrCreate(['key' => $data['key']], $data);
        }
    }
}
