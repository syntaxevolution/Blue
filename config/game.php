<?php

/*
|--------------------------------------------------------------------------
| Clash Wars (internal: CashWars Reimagined) — Game Configuration
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
    | Stats & Scaling — hard cap 50, prestige extended to [21,50]
    |--------------------------------------------------------------------------
    */
    'stats' => [
        'hard_cap' => 50,
        'soft_plateau_start' => 15,
        'scaling' => [
            'linear_range' => [1, 15],
            'partial_range' => [16, 20],
            'partial_efficiency' => 0.6,
            'prestige_range' => [21, 50],
            'prestige_efficiency' => 0.3,
        ],
        'starting' => [
            'strength' => 1,
            'fortification' => 0,
            'stealth' => 0,
            'security' => 0,
        ],
        // When true, stat-add items can only be purchased once per key.
        'stat_items_single_purchase' => true,
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
        // Loot curve: loot_pct = min(loot_ceiling, loot_base_pct + loot_scale_factor * finalScore)
        'loot_base_pct' => 0.05,
        'loot_scale_factor' => 0.15,
        'loot_ceiling_pct' => 0.20,
        'raid_cooldown_hours' => 12,
        'spy_decay_hours' => 24,
        // When true, a defender who is physically on their base tile
        // gets scaledStat(strength) added to their fortification for
        // defense. This is the first real incentive to return home
        // before running out of moves.
        'at_base_defense_bonus_enabled' => true,
        'spy' => [
            'depth_1_grants' => 'attack_auth',
            'depth_2_grants' => 'cash_and_fort',
            'depth_3_grants' => 'guaranteed_escape',
            // Spy detection: chance starts at base, +per_security_diff for each
            // point target.security exceeds spy.stealth, clamped to [min, max].
            'detection_chance_base' => 0.20,
            'detection_per_security_diff' => 0.02,
            'detection_chance_min' => 0.02,
            'detection_chance_max' => 0.95,
            // Spy success curve (was hardcoded in SpyService):
            //   success = clamp(success_base + success_per_stealth_diff * max(0, stealth - security), min, max)
            'success_base' => 0.30,
            'success_per_stealth_diff' => 0.05,
            'success_chance_min' => 0.10,
            'success_chance_max' => 0.95,
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
        // Teleport is a transport-like action; costs are in the 'teleport' block below.
        'teleport' => ['move_cost' => 1],
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
        // When true, purchases like extra_moves_pack may raise moves_current
        // above the bank cap. They do NOT raise the cap itself.
        'allow_overflow_from_purchases' => true,
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
        // Legacy key from the original spec — kept for reference but
        // unused. Refill is now driven by `field_refill_hours` below,
        // which measures from full depletion, not from last regen.
        'drill_point_regen_hours' => 12,
        // Hours a fully-depleted field waits before all of its drill
        // points refill. Lazy reconcile: OilFieldRegenService applies
        // the reset on the next read (drill attempt or map state build)
        // after `depleted_at + field_refill_hours` is in the past.
        'field_refill_hours' => 6,
        'daily_limit_per_field' => 5,
        // Tech item break roll fires per drill use on non-starter drills.
        // 1% default. Tier 1 (implicit starter, not in player_items) is exempt.
        'break_chance_pct' => 0.01,
        'quality_weights' => [
            'dry' => 0.35,
            'trickle' => 0.40,
            'standard' => 0.20,
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
            5 => ['name' => 'Industrial Rig', 'yield_multiplier' => 2.2, 'eliminates_dry' => true,  'guarantees_standard_plus' => 0],
            6 => ['name' => 'Refinery',       'yield_multiplier' => 2.6, 'eliminates_dry' => true,  'guarantees_standard_plus' => 1],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Items — break/repair/abandon lifecycle
    |--------------------------------------------------------------------------
    | Currently only drill-tier items break (effect key 'set_drill_tier').
    | To extend: add another effect key to eligible_effect_keys and hook
    | the break roll into the relevant service (e.g., TransportMovementService).
    */
    'items' => [
        'break' => [
            'enabled' => true,
            'eligible_effect_keys' => ['set_drill_tier'],
            'repair_cost_pct' => 0.10,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | World — initial size, density, auto-growth, abandonment decay
    |--------------------------------------------------------------------------
    */
    'world' => [
        'initial_radius' => 25,
        'spawn_band_radius' => 12,
        'density' => [
            'oil_fields_per_tile' => 0.125,
            'posts_per_tile' => 0.025,
            'landmarks_per_tile' => 0.005,
            'casinos_per_tile' => 0.004,
        ],
        'growth' => [
            // Kill-switch for WorldService::expandWorld (and the nightly
            // world:grow command). Flip to false in the admin panel to
            // pause all automatic map expansion without redeploying.
            'enabled' => true,
            'trigger_players_per_tile' => 0.015,
            // Ring width per growth pass. Interpretation: each nightly
            // run that fires adds exactly ONE concentric integer ring
            // around the current frontier. The world keeps growing a
            // ring per night until density falls below the trigger.
            'expansion_ring_width' => 1,
        ],
        'abandonment' => [
            'days_inactive' => 90,
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
    | New Player — 48h immunity, starter loadout, email verification gate
    |--------------------------------------------------------------------------
    */
    'new_player' => [
        'immunity_hours' => 48,
        'starting_cash' => 5.00,
        'starting_strength' => 1,
        'starting_drill_tier' => 1,
        // When true, users must verify their email address before game
        // routes become accessible. Login is still allowed, but the
        // verified middleware redirects to the verification notice.
        'require_email_verification' => true,
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
    | Usernames — case-insensitive unique, alphanumeric, locked once claimed
    |--------------------------------------------------------------------------
    */
    'username' => [
        'min_length' => 5,
        'max_length' => 15,
        // Regex applied client + server side. Alphanumeric only.
        'pattern' => '/^[a-zA-Z0-9]{5,15}$/',
    ],

    /*
    |--------------------------------------------------------------------------
    | General Store — extra moves, transport modes, teleporter
    |--------------------------------------------------------------------------
    */
    'general_store' => [
        'extra_moves' => [
            'enabled' => true,
            // Pricing rationale: Caffeine Tin (120/15 = 8 barrels/move)
            // and Emergency Ration (150/20 = 7.5/move) are the cheap
            // consumables. Extra Moves Pack is the "big click" burst —
            // you pay a convenience premium of ~30 barrels/move to get
            // +50 in one action instead of spamming the cheaper items.
            // This keeps all three meaningful and prevents EMP from
            // strictly dominating via sheer click-efficiency.
            'cost_barrels' => 1500,
            'amount' => 50,
        ],
        // Stackable bank-cap upgrade (Iron Lungs item). Each copy grants
        // bank_cap_bonus = 10. Max stacks is a soft cap enforced by the
        // shop guard so a whale can't push their cap to absurd values
        // before the economy has data to balance against.
        'iron_lungs' => [
            'max_stacks' => 10,
        ],
        // Transport modes. walking is the implicit default (always owned).
        // spaces = tiles traversed per button press
        // fuel   = oil_barrels deducted per button press
        // flags  = special behaviours (reveal_path, reveal_cardinal_neighbours)
        'transport' => [
            'bicycle'     => ['cost_barrels' => 500,    'spaces' => 2,  'fuel' => 0,  'flags' => []],
            'motorcycle'  => ['cost_barrels' => 1500,   'spaces' => 5,  'fuel' => 1,  'flags' => []],
            'sand_runner' => ['cost_barrels' => 5000,   'spaces' => 10, 'fuel' => 2,  'flags' => ['reveal_cardinal_neighbours']],
            'helicopter'  => ['cost_barrels' => 25000,  'spaces' => 25, 'fuel' => 5,  'flags' => []],
            'airplane'    => ['cost_barrels' => 100000, 'spaces' => 50, 'fuel' => 10, 'flags' => ['reveal_path']],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Teleporter — buy once, use unlimited, validate destination before charging
    |--------------------------------------------------------------------------
    */
    'teleport' => [
        'enabled' => true,
        'purchase_cost_barrels' => 250000,
        'cost_barrels' => 5000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications — Reverb broadcasting + activity log
    |--------------------------------------------------------------------------
    */
    'notifications' => [
        'broadcast_enabled' => true,
        'activity_log_retention_days' => 90,
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
        'name_max_length' => 50,
        'tag_max_length' => 8,
        'motto_max_length' => 200,
        'creation_cost_cash' => 10.00,
        'journal' => [
            'enabled' => true,
            'max_entries_per_mdn' => 500,
            'body_max_length' => 1000,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bots — autonomous AI players spawned via `php artisan bots:spawn`
    |--------------------------------------------------------------------------
    | Bots are real Player rows that call the exact same domain services a
    | human does. Difficulty controls action weights, target selection, and
    | spending thresholds. Primary objective across all tiers is maximising
    | Akzar Cash. Tick cadence is driven by the Laravel scheduler — see
    | routes/console.php for the cron hook.
    */
    'bots' => [
        'tick_interval_minutes' => 5,
        'actions_per_tick_max' => 3,
        'email_domain' => 'bots.cashclash.local',
        // Word pool used by bots:spawn when auto-generating names.
        'name_pool' => [
            'adjectives' => ['Rusty', 'Dusty', 'Silent', 'Iron', 'Sandy', 'Shady', 'Greasy', 'Feral', 'Tinny', 'Cobalt', 'Brass', 'Hollow'],
            'nouns'      => ['Jack', 'Whip', 'Prowler', 'Hound', 'Scrap', 'Fang', 'Coil', 'Drifter', 'Ghost', 'Shark', 'Badger', 'Vulture'],
        ],
        'difficulty' => [
            'easy' => [
                'label' => 'Easy',
                'action_weights' => [
                    'drill'  => 70,
                    'shop'   => 20,
                    'spy'    => 5,
                    'attack' => 5,
                ],
                // Minimum barrels in reserve before the bot will buy stat
                // items, drill upgrades, or transports. Keeps easy bots
                // from going broke on upgrades when they should be drilling.
                'upgrade_threshold_barrels' => 500,
                'min_target_cash' => 20.0,
                'risk_tolerance' => 0.3,
                'travel_range_tiles' => 6,
            ],
            'normal' => [
                'label' => 'Normal',
                'action_weights' => [
                    'drill'  => 50,
                    'shop'   => 20,
                    'spy'    => 15,
                    'attack' => 15,
                ],
                'upgrade_threshold_barrels' => 300,
                'min_target_cash' => 10.0,
                'risk_tolerance' => 0.55,
                'travel_range_tiles' => 12,
            ],
            'hard' => [
                'label' => 'Hard',
                'action_weights' => [
                    'drill'  => 35,
                    'shop'   => 15,
                    'spy'    => 25,
                    'attack' => 25,
                ],
                'upgrade_threshold_barrels' => 100,
                'min_target_cash' => 5.0,
                'risk_tolerance' => 0.8,
                'travel_range_tiles' => 20,
            ],
        ],
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

    /*
    |--------------------------------------------------------------------------
    | Casino — Roughneck's Saloon (interlinked gambling tiles)
    |--------------------------------------------------------------------------
    | Every casino tile is an entry point to a shared global game space.
    | Players at different casino tiles see the same tables and can play
    | together. Entry fee charged per visit. Each game has Cash and Oil
    | table variants. All odds are realistic defaults, tunable from admin.
    */
    'casino' => [
        'enabled' => true,
        'entry_fee_barrels' => 50,
        'session_duration_minutes' => 120,
        'names' => [
            "Roughneck's Saloon",
            'The Lucky Derrick',
            "Gusher's Den",
            'The Pipeline Lounge',
            'Barrel & Bone Casino',
        ],

        'slots' => [
            'enabled' => true,
            'house_edge_pct' => 0.05,
            'min_bet_cash' => 0.10,
            'max_bet_cash' => 500.00,
            'min_bet_barrels' => 10,
            'max_bet_barrels' => 50000,
            'reel_count' => 3,
            // Minimum seconds between consecutive spins by the same player.
            // Protects against bot/script spam beyond the global throttle.
            'min_interval_seconds' => 1,
            // Weights tuned for ~7% house edge (EV ≈ 0.93 per spin).
            // The 'blank' symbol is the main loss source — it has no payout
            // line and absorbs the volume that cherry/bar used to dominate.
            // Pay-table order matters: first matching rule wins. 3-of-a-kind
            // entries must appear before 2-of-a-kind for the same symbol.
            'symbols' => [
                'cherry'     => ['weight' => 28, 'display' => 'Cherry'],
                'bar'        => ['weight' => 22, 'display' => 'BAR'],
                'double_bar' => ['weight' => 14, 'display' => '2xBAR'],
                'triple_bar' => ['weight' => 10, 'display' => '3xBAR'],
                'seven'      => ['weight' => 8,  'display' => '7'],
                'diamond'    => ['weight' => 5,  'display' => 'Diamond'],
                'akzar'      => ['weight' => 2,  'display' => 'AKZAR'],
                'blank'      => ['weight' => 20, 'display' => '—'],
            ],
            // Pay table tuned to ~5.9% house edge (EV ≈ 0.941) against
            // the symbol weights above. Recalculate if symbol weights
            // change — the math is balanced around them.
            'pay_table' => [
                ['akzar', 3, 500],       // Massive jackpot, ~1 in 163k spins
                ['diamond', 3, 250],     // ~1 in 10k spins
                ['seven', 3, 150],       // ~1 in 2.5k spins
                ['triple_bar', 3, 100],
                ['double_bar', 3, 60],
                ['bar', 3, 25],
                ['cherry', 3, 10],
                ['cherry', 2, 1],        // Consolation push on ~15% of spins
                ['any_bar', 3, 2],       // Mixed bars, ~6% of spins
            ],
        ],

        'roulette' => [
            'enabled' => true,
            'betting_window_seconds' => 60,
            'spin_pause_seconds' => 5,
            'min_bet_cash' => 0.10,
            'max_bet_cash' => 500.00,
            'min_bet_barrels' => 10,
            'max_bet_barrels' => 50000,
            'max_bets_per_round' => 20,
            'payouts' => [
                'straight' => 35,
                'split' => 17,
                'street' => 11,
                'corner' => 8,
                'line' => 5,
                'column' => 2,
                'dozen' => 2,
                'even_money' => 1,
            ],
            'tables_per_currency' => 1,
        ],

        'blackjack' => [
            'enabled' => true,
            'min_bet_cash' => 0.10,
            'max_bet_cash' => 500.00,
            'min_bet_barrels' => 10,
            'max_bet_barrels' => 50000,
            'max_seats' => 5,
            'deck_count' => 6,
            'reshuffle_penetration_pct' => 0.75,
            'dealer_hits_soft_17' => false,
            'blackjack_payout_ratio' => 1.5,
            'insurance_enabled' => true,
            'surrender_enabled' => true,
            'double_after_split' => true,
            'max_splits' => 3,
            'turn_timer_seconds' => 30,
            'tables_per_currency' => 1,
        ],

        'holdem' => [
            'enabled' => true,
            'min_players' => 2,
            'max_seats' => 6,
            'turn_timer_seconds' => 30,
            'min_buy_in_multiplier' => 20,
            'max_buy_in_multiplier' => 100,
            'rake_pct' => 0.05,
            'rake_cap_cash' => 5.00,
            'rake_cap_barrels' => 500,
            // Default blind levels used at auto-created tables. Per-currency
            // since cash and oil tables have vastly different denominations.
            'default_blinds' => [
                'akzar_cash' => ['small' => 0.05, 'big' => 0.10],
                'oil_barrels' => ['small' => 5, 'big' => 10],
            ],
            'blinds' => [
                'cash' => [
                    ['small' => 0.05, 'big' => 0.10],
                    ['small' => 0.25, 'big' => 0.50],
                    ['small' => 1.00, 'big' => 2.00],
                ],
                'barrels' => [
                    ['small' => 5, 'big' => 10],
                    ['small' => 25, 'big' => 50],
                    ['small' => 100, 'big' => 200],
                ],
            ],
            'tables_per_blind_level' => 1,
        ],

        'chat' => [
            'enabled' => true,
            'max_message_length' => 200,
            'rate_limit_per_minute' => 10,
            'history_load_count' => 50,
        ],
    ],

];
