<?php

/*
|--------------------------------------------------------------------------
| CashWars Reimagined — Game Configuration
|--------------------------------------------------------------------------
|
| All balance numbers, costs, cooldowns, caps, RNG ranges, and probabilities
| live here. Game code must NEVER hardcode these values — always resolve via
| the GameConfig facade, which checks this file as the final fallback after
| the in-memory cache and the game_settings DB overrides.
|
| Every key below can be overridden live from the admin panel without a
| deployment. Defaults here are the spec'd values from gameplay-ultraplan.md
| and technical-ultraplan.md §4.3.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Stats & Scaling
    |--------------------------------------------------------------------------
    */
    'stats' => [
        'hard_cap' => 25,
        'soft_plateau_start' => 15,
        'scaling' => [
            'linear_range' => [1, 15],
            'partial_range' => [16, 20],
            'partial_efficiency' => 0.6,
            'prestige_range' => [21, 25],
            'prestige_efficiency' => 0.3,
        ],
        'starting' => [
            'strength' => 1,
            'fortification' => 0,
            'stealth' => 0,
            'security' => 0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Combat — deterministic core + ±10–15% RNG band
    |--------------------------------------------------------------------------
    */
    'combat' => [
        'formula_version' => 'v1',
        'rng_band_min' => -0.10,
        'rng_band_max' => 0.15,
        'loot_ceiling_pct' => 0.20,
        'raid_cooldown_hours' => 12,
        'spy_decay_hours' => 24,
        'spy' => [
            'depth_1_grants' => 'attack_auth',
            'depth_2_grants' => 'cash_and_fort',
            'depth_3_grants' => 'guaranteed_escape',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Action Move Costs
    |--------------------------------------------------------------------------
    */
    'actions' => [
        'travel' => ['move_cost' => 1],
        'drill' => ['move_cost' => 2],
        'spy' => ['move_cost' => 3],
        'attack' => ['move_cost' => 5],
        'shop' => ['move_cost' => 0],
    ],

    /*
    |--------------------------------------------------------------------------
    | Moves / Energy Regeneration
    |--------------------------------------------------------------------------
    | regen_tick_seconds = 86400 / daily_regen (continuous trickle model)
    */
    'moves' => [
        'daily_regen' => 200,
        'regen_mode' => 'continuous',
        'regen_tick_seconds' => 432,
        'bank_cap_multiplier' => 1.75,
        'sponsor' => [
            'cap_pct_of_monthly' => 0.25,
            'cooldown_hours_per_offer' => 720,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Drilling — 5×5 drill point sub-grid, 6 equipment tiers
    |--------------------------------------------------------------------------
    */
    'drilling' => [
        'grid_size' => 5,
        'drill_point_regen_hours' => 12,
        'quality_weights' => [
            'dry' => 0.30,
            'trickle' => 0.40,
            'standard' => 0.25,
            'gusher' => 0.05,
        ],
        'yields' => [
            'dry' => [0, 0],
            'trickle' => [1, 3],
            'standard' => [4, 8],
            'gusher' => [12, 25],
        ],
        'equipment' => [
            1 => ['name' => 'Dentist Drill',  'yield_multiplier' => 1.0, 'eliminates_dry' => false, 'guarantees_standard_plus' => 0],
            2 => ['name' => 'Shovel Rig',     'yield_multiplier' => 1.3, 'eliminates_dry' => false, 'guarantees_standard_plus' => 0],
            3 => ['name' => 'Medium Drill',   'yield_multiplier' => 1.6, 'eliminates_dry' => false, 'guarantees_standard_plus' => 0],
            4 => ['name' => 'Heavy Drill',    'yield_multiplier' => 2.0, 'eliminates_dry' => false, 'guarantees_standard_plus' => 0],
            5 => ['name' => 'Industrial Rig', 'yield_multiplier' => 2.5, 'eliminates_dry' => true,  'guarantees_standard_plus' => 0],
            6 => ['name' => 'Refinery',       'yield_multiplier' => 3.0, 'eliminates_dry' => true,  'guarantees_standard_plus' => 1],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | World — initial size, density, auto-growth, abandonment decay
    |--------------------------------------------------------------------------
    */
    'world' => [
        'initial_radius' => 25,
        'density' => [
            'oil_fields_per_tile' => 0.125,
            'posts_per_tile' => 0.025,
            'landmarks_per_tile' => 0.005,
        ],
        'growth' => [
            'trigger_players_per_tile' => 0.015,
            'expansion_ring_width' => 10,
        ],
        'abandonment' => [
            'days_inactive' => 30,
            'ruin_loot_min' => 0.5,
            'ruin_loot_max' => 2.0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Intel — espionage currency, 24h spy decay, 1%/day intel decay
    |--------------------------------------------------------------------------
    */
    'intel' => [
        'value_anchor_barrels' => 5,
        'decay_pct_per_day' => 0.01,
        'earn' => [
            'spy_depth_1' => 1,
            'spy_depth_2' => 2,
            'spy_depth_3' => 3,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | New Player — 48h immunity, starter loadout
    |--------------------------------------------------------------------------
    */
    'new_player' => [
        'immunity_hours' => 48,
        'starting_cash' => 5.00,
        'starting_strength' => 1,
        'starting_drill_tier' => 1,
        'starter_pack' => [
            'tutorial_compass' => 1,
            'paper_map_1' => 1,
            'compass' => 1,
            'seismic_reading' => 1,
            'fuel_can' => 1,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | MDN (Mutual Defense Network) — 50 member cap, same-MDN attacks blocked
    |--------------------------------------------------------------------------
    */
    'mdn' => [
        'max_members' => 50,
        'join_leave_cooldown_hours' => 24,
        'same_mdn_attacks_blocked' => true,
        'formal_alliances_prevent_attacks' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Bankruptcy — no cash floor, A0.25 one-time pity stipend
    |--------------------------------------------------------------------------
    */
    'bankruptcy' => [
        'pity_stipend' => 0.25,
        'pity_stipend_cooldown_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Seasons — soft monthly, cosmetic-only rewards, no wipes
    |--------------------------------------------------------------------------
    */
    'seasons' => [
        'length_days' => 30,
        'rewards_type' => 'cosmetic_only',
        'wipe_on_rollover' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | RNG Service — source, audit logging toggle, replay toggle
    |--------------------------------------------------------------------------
    */
    'rng' => [
        'source' => 'xoshiro256',
        'record_mode' => false,
        'replay_mode' => false,
    ],

];
