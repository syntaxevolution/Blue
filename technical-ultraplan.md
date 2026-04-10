# CashWars Reimagined — Technical Ultraplan v0.1

Companion to `gameplay-ultraplan.md`. Describes how the locked gameplay spec is built in Laravel + Vue + Inertia.js on a Debian/DirectAdmin VPS for an initial ~100-user launch, with a clean API surface so a mobile client can be added later.

**Development machine:** Windows
**Production server:** Debian Linux VPS running DirectAdmin (shared with other side projects, will move to dedicated VPS if successful)

---

## 0. Guiding Technical Principles

1. **Configurability over hardcoding.** Every balance number, RNG range, probability, cost, cooldown, cap, and threshold lives in editable configuration — never in code. Tuning the game should never require a deployment.
2. **Deterministic where possible, RNG where flavorful.** All RNG uses seeded, auditable sources so outcomes can be reproduced in tests and reviewed after disputes.
3. **API-first, web-first.** Inertia powers the web client; a parallel `/api/v1/*` REST layer powers future mobile. Both consume the same service layer underneath.
4. **Small-scale sane defaults.** Single-VPS shared with other projects. Everything fits on one box. No distributed systems until needed.
5. **Minimal PII.** Collect only what's necessary.
6. **Anti-bot by design.** The original game died to bots; we build defensive measures in from day one.
7. **Observability from day one.** Logs, metrics, and an admin panel to inspect/change configuration and review incidents.

---

## 1. Stack Summary

| Layer | Choice |
|---|---|
| Runtime | PHP 8.3+ |
| Framework | Laravel 11 |
| Frontend framework | Vue 3 (Composition API) |
| Inertia shim | Inertia.js 2.x (SPA mode) |
| Styling | Tailwind CSS 3 |
| Component primitives | Headless UI (Vue) |
| State management | Pinia (ephemeral UI state only) |
| Build tool | Vite |
| Database | MySQL 8 |
| Cache / Queue / Pub/Sub | Redis 7 |
| Queue runner | Laravel Horizon |
| WebSockets | Laravel Reverb (fallback: Pusher free tier) |
| Auth scaffolding | Laravel Fortify |
| Social auth | Laravel Socialite (Google, Discord, Apple) |
| API tokens (mobile) | Laravel Sanctum |
| Email | Resend or Postmark (transactional) |
| Bot defense | Cloudflare Turnstile |
| Web server | Nginx |
| Process supervisor | Supervisor |
| Testing | Pest |
| OS (prod) | Debian stable |
| Control panel (prod) | DirectAdmin |
| Local dev (Windows) | Laravel Herd or Laravel Sail (Docker) |
| Deployment | Manual git push/pull + shell script |

---

## 2. High-Level Architecture

```
             +---------------------+
             |  Web Browser (Vue)  |
             +----------+----------+
                        |
                  Inertia.js (session cookies)
                        |
             +----------v----------+      +----------------+
             |  Nginx (Debian VPS) +------> Laravel Reverb |
             +----------+----------+      +----------------+
                        |
             +----------v----------+
             |  Laravel 11 (PHP-   |
             |  FPM, single app)   |
             +----+-----+-----+----+
                  |     |     |
          +-------+     |     +-------+
          |             |             |
   +------v----+ +------v----+ +------v----+
   |  MySQL 8  | |  Redis 7  | | Queues    |
   |           | |           | | (Horizon) |
   +-----------+ +-----------+ +-----------+

     +---------------------+
     |  Future Mobile App  |
     +----------+----------+
                |
          HTTPS REST via /api/v1
          (Sanctum tokens)
                |
            Same Laravel app, same service layer
```

**Key point:** Web (Inertia) and mobile (REST) controllers are **thin**. All game logic lives in a **shared service/domain layer** so both entry points are consistent.

---

## 3. Dual-Layer Controller Pattern

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── Web/                  (Inertia controllers)
│   │   │   ├── MapController.php
│   │   │   ├── BaseController.php
│   │   │   ├── CombatController.php
│   │   │   └── ...
│   │   └── Api/
│   │       └── V1/               (REST controllers for mobile)
│   │           ├── MapController.php
│   │           ├── BaseController.php
│   │           ├── CombatController.php
│   │           └── ...
│   ├── Requests/                 (Form request validation, shared)
│   ├── Resources/                (API JSON resources)
│   └── Middleware/
├── Domain/                       (Shared game logic)
│   ├── World/
│   │   ├── WorldService.php
│   │   ├── TileRepository.php
│   │   ├── FogOfWarService.php
│   │   └── WorldGrowthService.php
│   ├── Player/
│   │   ├── PlayerService.php
│   │   ├── MoveRegenService.php
│   │   └── BankruptcyService.php
│   ├── Drilling/
│   │   ├── DrillService.php
│   │   └── OilFieldRegenService.php
│   ├── Combat/
│   │   ├── SpyService.php
│   │   ├── AttackService.php
│   │   └── CombatFormula.php
│   ├── Economy/
│   │   ├── ShopService.php
│   │   ├── IntelService.php
│   │   └── AuctionService.php
│   ├── Mdn/
│   │   ├── MdnService.php
│   │   └── MdnAllianceService.php
│   ├── Journal/
│   │   └── JournalService.php
│   └── Config/
│       ├── GameConfig.php        (config access facade)
│       └── RngService.php        (seeded RNG)
├── Models/
├── Events/
├── Listeners/
├── Jobs/
├── Notifications/
└── Providers/
```

- **Controllers are thin:** validate input, call a service, return Inertia page or JSON resource.
- **Services are pure PHP:** no HTTP awareness, fully testable.
- **Models are skinny:** Eloquent relationships and casts only, no logic.
- **Form Requests** are shared between Web and Api controllers via invocation from both.

---

## 4. Configuration System (Critical — Configurability Pillar)

### 4.1 Two layers of config

1. **Static code config (`config/game.php`)** — shipped defaults, version-controlled, used as fallback
2. **Dynamic DB config (`game_settings` table)** — overrides defaults, editable via admin panel without deployment

### 4.2 Config access pattern

All game code goes through a single facade:

```php
// NEVER in game code:
$cost = 5;
$cap = 25;

// ALWAYS in game code:
$cost = GameConfig::get('actions.attack.move_cost');
$cap = GameConfig::get('stats.hard_cap');
```

`GameConfig::get($key)` resolves in this order:
1. Check in-memory cache
2. Check Redis cache (TTL ~60s)
3. Check `game_settings` DB table
4. Fall back to `config/game.php` static default
5. Throw if key unknown (strict mode in dev, warn in prod)

### 4.3 Categories of tunables

Every one of these is a configurable key:

**Stats & Combat**
- `stats.hard_cap` (default 25)
- `stats.soft_plateau_start` (default 15)
- `stats.scaling.linear_range` (default [1,15])
- `stats.scaling.partial_range` (default [16,20])
- `stats.scaling.partial_efficiency` (default 0.6)
- `stats.scaling.prestige_range` (default [21,25])
- `stats.scaling.prestige_efficiency` (default 0.3)
- `combat.rng_band_min` (default -0.10)
- `combat.rng_band_max` (default 0.15)
- `combat.loot_ceiling_pct` (default 0.20)
- `combat.raid_cooldown_hours` (default 12)
- `combat.spy_decay_hours` (default 24)
- `combat.spy.depth_1_grants` (default "attack_auth")
- `combat.spy.depth_2_grants` (default "cash_and_fort")
- `combat.spy.depth_3_grants` (default "guaranteed_escape")

**Actions (move costs)**
- `actions.travel.move_cost` (default 1)
- `actions.drill.move_cost` (default 2)
- `actions.spy.move_cost` (default 3)
- `actions.attack.move_cost` (default 5)
- `actions.shop.move_cost` (default 0)

**Moves & Regen**
- `moves.daily_regen` (default 200)
- `moves.regen_mode` (default "continuous") — continuous trickle vs dump
- `moves.regen_tick_seconds` (default 432) — 200 moves / 86400s
- `moves.bank_cap_multiplier` (default 1.75)
- `moves.sponsor.cap_pct_of_monthly` (default 0.25)
- `moves.sponsor.cooldown_hours_per_offer` (default 720)

**Drilling**
- `drilling.grid_size` (default 5)
- `drilling.drill_point_regen_hours` (default 12)
- `drilling.quality_weights.dry` (default 0.30)
- `drilling.quality_weights.trickle` (default 0.40)
- `drilling.quality_weights.standard` (default 0.25)
- `drilling.quality_weights.gusher` (default 0.05)
- `drilling.yields.dry` (default [0,0])
- `drilling.yields.trickle` (default [1,3])
- `drilling.yields.standard` (default [4,8])
- `drilling.yields.gusher` (default [12,25])
- `drilling.equipment.{tier}.yield_multiplier`
- `drilling.equipment.{tier}.eliminates_dry` (bool)
- `drilling.equipment.{tier}.guarantees_standard_plus` (int count)

**World**
- `world.initial_radius` (default 25) — tiles from origin
- `world.density.oil_fields_per_tile` (default 0.125)
- `world.density.posts_per_tile` (default 0.025)
- `world.density.landmarks_per_tile` (default 0.005)
- `world.growth.trigger_players_per_tile` (default 0.015)
- `world.growth.expansion_ring_width` (default 10)
- `world.abandonment.days_inactive` (default 30)
- `world.abandonment.ruin_loot_min` (default 0.5)
- `world.abandonment.ruin_loot_max` (default 2.0)

**Intel**
- `intel.value_anchor_barrels` (default 5)
- `intel.decay_pct_per_day` (default 0.01)
- `intel.earn.spy_depth_1` (default 1)
- `intel.earn.spy_depth_2` (default 2)
- `intel.earn.spy_depth_3` (default 3)

**New Player**
- `new_player.immunity_hours` (default 48)
- `new_player.starting_cash` (default 5.00)
- `new_player.starting_strength` (default 1)
- `new_player.starter_pack_items` (array)

**MDN**
- `mdn.max_members` (default 50)
- `mdn.join_leave_cooldown_hours` (default 24)
- `mdn.same_mdn_attacks_blocked` (default true)
- `mdn.formal_alliances_prevent_attacks` (default false)

**Bankruptcy**
- `bankruptcy.pity_stipend` (default 0.25)
- `bankruptcy.pity_stipend_cooldown_hours` (default 24)

**Seasons**
- `seasons.length_days` (default 30)
- `seasons.rewards_type` (default "cosmetic_only")
- `seasons.wipe_on_rollover` (default false)

### 4.4 Admin panel

- Laravel Filament-based admin panel at `/admin`
- Game settings viewer/editor with:
  - Search/filter by key
  - Type validation (int / float / bool / array / enum)
  - Change audit log (who changed what, when, old→new value)
  - One-click revert to default
  - Diff view vs shipped defaults
- Protected by a gate on a `is_admin` boolean on users
- Changes are flushed from Redis cache immediately on save

---

## 5. Seeded RNG Service

Every random roll goes through `RngService`:

```php
$yield = $rng->rollInt('drilling.standard', $drillPointId, 4, 8);
```

Features:
- Seeded per-event so the same input reproduces the same output
- Can be put into "record mode" to log every roll (for dispute review and balance analysis)
- Can be put into "replay mode" for tests (deterministic outcomes)
- Exposes helpers: `rollInt`, `rollFloat`, `rollBool`, `rollWeighted`, `rollBand`
- The RNG source per category is configurable (`mt_rand`, `random_int`, seeded `\Random\Randomizer`) — defaults to `\Random\Randomizer` with per-event seed

All roll outcomes are stored in a `rng_log` table (optional, toggleable) with:
- Event ID
- Category
- Input seed
- Output
- Configured range/weights at time of roll

This lets us investigate "I drilled 10 gushers in a row" claims factually.

---

## 6. Database Schema

MySQL 8, InnoDB. All timestamps UTC. Soft deletes where meaningful.

### 6.1 Identity

**users**
- id (bigint PK)
- username (varchar 20, unique, immutable after creation, case-insensitive index)
- email (varchar 255, unique, nullable if social-only)
- email_verified_at (datetime, nullable)
- password (varchar 255, nullable if social-only)
- preferred_timezone (varchar 64, default 'UTC')
- locale (varchar 10, default 'en')
- is_admin (bool, default false)
- last_active_at (datetime, nullable)
- created_at, updated_at

**social_accounts**
- id, user_id, provider (google/discord/apple), provider_user_id, created_at
- Unique on (provider, provider_user_id)

**personal_access_tokens** — standard Sanctum table for mobile clients

### 6.2 World

**tiles**
- id (bigint PK)
- x (int, signed) — east-west, negative = west
- y (int, signed) — north-south, negative = south
- type (enum: base, oil_field, post, wasteland, landmark, auction, ruin)
- subtype (varchar nullable) — e.g. "strength_post", "general_store", "desert", "forest"
- flavor_text (varchar 255, nullable)
- seed (bigint) — procedural generation seed
- generated_at (datetime)
- Unique on (x, y)
- Spatial-ish index on (x, y) for range queries

**oil_fields**
- id, tile_id (FK unique)
- drill_grid_rows (int) — typically 5
- drill_grid_cols (int) — typically 5
- last_regen_at (datetime)

**drill_points**
- id, oil_field_id (FK)
- grid_x (tinyint 0-4)
- grid_y (tinyint 0-4)
- quality (enum: dry, trickle, standard, gusher)
- drilled_at (datetime, nullable) — set when depleted until regen
- Unique on (oil_field_id, grid_x, grid_y)

**posts**
- id, tile_id (FK unique)
- post_type (enum: strength, stealth, fort, tech, general, auction)
- name (varchar — generated flavorful name like "Dusty Joe's Arms Depot")

### 6.3 Players

**players**
- id, user_id (FK unique)
- base_tile_id (FK)
- akzar_cash (decimal 12,2)
- oil_barrels (int unsigned)
- intel (int unsigned)
- moves_current (int)
- moves_updated_at (datetime) — for regen calculation
- sponsor_moves_used_this_cycle (int)
- strength (tinyint, default 1)
- fortification (tinyint, default 0)
- stealth (tinyint, default 0)
- security (tinyint, default 0)
- drill_tier (tinyint, default 1)
- mdn_id (FK, nullable)
- mdn_joined_at (datetime, nullable)
- mdn_left_at (datetime, nullable)
- immunity_expires_at (datetime)
- last_bankruptcy_at (datetime, nullable)
- created_at, updated_at

**player_items**
- id, player_id, item_key, quantity, charges_remaining
- item_key references catalog config

### 6.4 Exploration / Fog of War

**tile_discoveries**
- player_id, tile_id, discovered_at
- Composite PK (player_id, tile_id)

**journal_entries**
- id, player_id, tile_id, note (varchar 500), created_at, updated_at
- Index on (player_id, tile_id)

**mdn_journal_entries**
- id, mdn_id, author_player_id, tile_id, note, created_at
- Helpful/unhelpful vote counts cached here

**mdn_journal_votes**
- id, entry_id, player_id, vote (enum: helpful, unhelpful), created_at
- Unique on (entry_id, player_id)

### 6.5 Combat & Intel

**spy_attempts**
- id, spy_player_id, target_base_tile_id, target_player_id, depth (1-3)
- success (bool), detected (bool)
- rng_seed, rng_output
- created_at
- Index on (spy_player_id, target_player_id, created_at)

**attacks**
- id, attacker_player_id, defender_player_id, defender_base_tile_id
- relied_on_spy_id (FK to spy_attempts)
- outcome (enum: success, failure, bankrupt_target, decoy)
- cash_stolen (decimal 12,2, default 0)
- attacker_escape (bool)
- rng_seed, rng_output
- created_at
- Index on (attacker_player_id, defender_player_id, created_at)

**intel_ledger**
- id, player_id, change (int signed, positive or negative)
- reason (varchar — "spy_depth_1", "decay", "hit_contract", etc.)
- created_at
- Index on (player_id, created_at)

### 6.6 MDN

**mdns**
- id, name (varchar 50, unique), tag (varchar 6), leader_player_id
- member_count (denormalized), created_at

**mdn_memberships**
- mdn_id, player_id, role (leader/officer/member), joined_at
- Composite PK

**mdn_alliances**
- id, mdn_a_id, mdn_b_id, declared_at
- Unique on sorted pair

### 6.7 Shops & Economy

**items_catalog** (static, seeded from config but editable via admin)
- item_key (PK)
- name, description, category, post_type
- price_barrels, price_cash, price_intel
- stat_boosts (JSON)
- effects (JSON)
- is_consumable (bool), charges (int nullable)
- is_auction_only (bool)

**purchases** (audit/history)
- id, player_id, item_key, quantity, total_price, currency, tile_id, created_at

**auctions**
- id, item_key, min_bid, current_bid, current_bidder_player_id, ends_at, created_at

**auction_bids**
- id, auction_id, player_id, amount, created_at

### 6.8 Game Config & RNG Audit

**game_settings**
- key (varchar PK)
- value (JSON — supports int/float/bool/string/array)
- type (enum for validation)
- description (text)
- updated_by_user_id (nullable)
- updated_at

**game_settings_audit**
- id, key, old_value (JSON), new_value (JSON), changed_by_user_id, changed_at

**rng_log** (optional, toggleable)
- id, event_type, event_id, category, seed, output (JSON), config_snapshot (JSON), created_at

### 6.9 Moderation & Anti-Abuse

**reports**
- id, reporter_player_id, reported_player_id, reason, details, status, created_at

**action_rate_log**
- id, player_id, action_type, created_at
- Index on (player_id, action_type, created_at)
- Used for per-second/per-minute rate limiting and behavioral analysis

**bans**
- id, player_id, reason, banned_by_user_id, expires_at (nullable), created_at

### 6.10 Sessions / Seasons

**seasons**
- id, name, starts_at, ends_at, tuning_snapshot (JSON)

**season_rewards_earned**
- player_id, season_id, rank_category, rank, cosmetic_reward_key, awarded_at

---

## 7. World Generation & Growth Algorithm

### 7.1 Initial generation

On first boot (or seeded migration):
1. Create a circular region of `world.initial_radius` tiles around origin (0, 0)
2. For each tile, deterministically roll type using a seed = `hash(x, y, world_seed)`:
   - Posts placed first via Poisson disk sampling for minimum spacing
   - Oil fields placed second
   - Landmarks placed third
   - Remainder = wasteland
3. One Auction House placed at fixed coordinate (configurable, default far from origin)
4. Origin tile reserved as "The Landing" landmark

### 7.2 Player spawn

- Find an empty wasteland tile within a configurable "spawn band" radius around origin
- Convert to player base
- Record in `tile_discoveries`

### 7.3 Growth trigger

Nightly job (`WorldGrowthJob`):
1. Count active players (defined by `last_active_at` within N days, configurable)
2. Calculate required area: `active_players / world.growth.trigger_players_per_tile`
3. If required > current area, expand by `world.growth.expansion_ring_width` tiles outward
4. Generate the new ring deterministically using the same seeded procedure
5. Log expansion event

### 7.4 Abandonment & ruin decay

Daily job (`AbandonmentJob`):
1. Find players inactive for `world.abandonment.days_inactive`
2. Convert their base tile to `ruin` type with a random reward amount
3. Player's items and stats remain in the DB; if they return, they claim a new base tile

---

## 8. Combat Formula

Expressed as a pure function in `Domain\Combat\CombatFormula`, fully driven by config keys. Pseudocode:

```
function resolveAttack(attacker, defender, rng):
    atkPower = scaledStat(attacker.strength)
    defPower = scaledStat(defender.fortification)
    
    baseOutcome = (atkPower - defPower) / (atkPower + defPower)
    // result in [-1, 1]; positive = attacker favored
    
    randomBand = rng.rollFloat('combat.band', combat.rng_band_min, combat.rng_band_max)
    finalScore = baseOutcome + randomBand
    
    if finalScore > 0:
        outcome = SUCCESS
        lootPct = min(combat.loot_ceiling_pct, 0.05 + 0.15 * finalScore)
        cashStolen = defender.cash * lootPct
    else:
        outcome = FAILURE
        escapeRoll = rng.rollFloat('combat.escape')
        if attacker.spyDepthOnTarget >= 3:
            escape = true
        else:
            escape = (scaledStat(attacker.stealth) > scaledStat(defender.security)) && (escapeRoll > 0.3)
        if not escape:
            penalty = min(attacker.cash * 0.05, defender.cash * 0.10)
            cashStolen = -penalty
```

**Scaled stat function** implements the soft plateau:
```
scaledStat(level):
    linear = min(level, 15)
    partial = clamp(level - 15, 0, 5) * 0.6
    prestige = clamp(level - 20, 0, 5) * 0.3
    return linear + partial + prestige
```

Every constant is a config key. The whole formula can be swapped by enabling a different `combat.formula_version` config key, with v1 and v2 coexisting for testing.

---

## 9. Fog of War Engine

### 9.1 Discovery
- On every tile arrival, `FogOfWarService::markDiscovered($playerId, $tileId)`
- Upserts `tile_discoveries` (indexed for fast reads)
- Fires `TileDiscovered` event for achievements

### 9.2 Visibility on read
- Map view for a player returns:
  - Current tile (full detail)
  - Edge hints for adjacent tiles (type-only, no details)
  - Discovered tiles list (cached in Redis per player, invalidated on discovery)
  - Journal notes overlay
  - Shared MDN journal overlay (if upgraded)

### 9.3 Paper Map items
- On use, `FogOfWarService::revealRadius($playerId, $centerTileId, $radius)`
- Bulk upsert into `tile_discoveries`
- Config: `items.paper_map_1.radius`, `items.paper_map_2.radius`, etc.

### 9.4 Performance
- Discovered tiles for a single player at scale: <10k entries typical
- Queried via composite PK, no joins
- Cached as a compact Redis set: `discovered:{player_id}` holding tile IDs
- Map view query is a single `WHERE id IN (...)` against tiles table

---

## 10. Move Regeneration Ticker

Continuous trickle model, cheaper than a global job:

**Lazy regen on read:** Whenever player state is loaded, `MoveRegenService::reconcile($player)`:
1. Calculates elapsed seconds since `moves_updated_at`
2. Computes moves earned: `elapsed / config('moves.regen_tick_seconds')`
3. Caps at `moves.bank_cap_multiplier * daily_regen`
4. Updates `moves_current` and `moves_updated_at` atomically (row lock)
5. Returns new state

No scheduled job needed for regen itself. Zero drift. Works the same on web and API reads.

**Sponsor bonus pipeline:**
- `SponsorOffer` model holds offer definitions (editable in admin)
- Player opts in → pending verification state
- Sponsor webhook confirms → `SponsorBonusService` grants moves
- Grant is capped at `moves.sponsor.cap_pct_of_monthly` of player's baseline
- Audit logged with sponsor offer ID, timestamp, move amount

---

## 11. Real-Time Events (Reverb / WebSocket)

### 11.1 Channels
- `private-player.{id}` — personal notifications (attack received, spy detected, etc.)
- `private-mdn.{id}` — MDN-wide notifications (member attacked, war declared)
- `presence-world` — online count (optional, low-priority)
- `private-leaderboard.{category}` — live leaderboard updates

### 11.2 Events pushed
- `YourBaseWasAttacked` — after an attack resolves, pushed to defender
- `YouWereSpiedOn` — only if defender's Security triggered detection
- `MdnMemberAttacked` — to MDN members
- `MdnWarDeclared`
- `AuctionEnding` — 5 minutes before auction close, to watchers
- `MovesReplenished` — optional heartbeat to UI so move counter auto-updates

### 11.3 Reverb on DirectAdmin
- Run as Supervisor service on port 8080 (internal)
- Nginx custom config (via DirectAdmin `custombuild` snippets) proxies `wss://domain/app/*` to `127.0.0.1:8080`
- Install location: `/usr/local/directadmin/custombuild/custom/nginx/` for persistence across DA rebuilds
- Reverb Supervisor config:
  ```ini
  [program:reverb]
  command=php /path/to/artisan reverb:start --host=127.0.0.1 --port=8080
  autostart=true
  autorestart=true
  user=www-data
  redirect_stderr=true
  stdout_logfile=/var/log/reverb.log
  ```

### 11.4 Fallback
If Reverb fights DirectAdmin too hard, switch to **Pusher free tier**. Code change is ~5 lines in `config/broadcasting.php` plus env vars — Laravel Echo abstracts the difference.

---

## 12. Background Jobs Catalog

Run via Laravel Horizon on Redis.

| Job | Frequency | Purpose |
|---|---|---|
| `OilFieldRegenJob` | Every 30 min | Reshuffle drill point qualities on fields past regen window |
| `IntelDecayJob` | Daily 04:00 UTC | Apply 1%/day decay to all player intel |
| `LeaderboardRollJob` | Weekly (Monday 00:00 UTC) | Snapshot and publish leaderboards |
| `WorldGrowthJob` | Daily 03:00 UTC | Check density, expand world if needed |
| `AbandonmentJob` | Daily 03:30 UTC | Mark abandoned bases as ruins |
| `SeasonRolloverJob` | Monthly 1st 00:00 UTC | Close season, award cosmetics, open next |
| `AuctionCloseJob` | Per auction end time | Settle winning bids |
| `MdnJournalRatingRecalcJob` | Hourly | Refresh vote-based sort scores |
| `RateLimitSweepJob` | Hourly | Prune old `action_rate_log` entries |
| `NewPlayerImmunityExpireJob` | Per expiry time | Remove immunity flag |
| `BankruptcyPityCheckJob` | Per bankruptcy event | Schedule next daily tick stipend |
| `TurnstileSignupVerificationJob` | On signup | Verify captcha token async |

All jobs respect config keys. All jobs are idempotent.

---

## 13. Authentication & Account Flow

### 13.1 Email + Password
1. Signup form: email, username, password, Turnstile token
2. `RegisterController` validates (including username rules), creates user, fires `Registered` event
3. Fortify sends verification email
4. User must click link to verify → `email_verified_at` set
5. Verified users can fully play; unverified can only access a local-only tutorial sandbox

### 13.2 Social (Socialite)
1. User clicks "Sign in with Google/Discord/Apple"
2. Redirect to provider → callback with code
3. Socialite fetches provider user info
4. If matching `social_accounts` row: log in as existing user
5. If no social account but email matches existing user: **prompt to link** (not automatic)
6. If brand new: require username selection, create user, auto-verify email (provider already verified), create social_account row
7. No provider profile data stored beyond provider ID

### 13.3 Username Rules
- Length 3–20 characters
- Allowed: `a-z`, `A-Z`, `0-9`, `_`, `-`
- Case-insensitive uniqueness (display case preserved)
- Reserved words list: admin, system, moderator, support, null, root, cashwars, akzar, etc. (configurable list)
- Profanity filter (configurable wordlist)
- Immutable after creation; admin override possible via Filament panel for legal/harassment cases

### 13.4 Mobile (Sanctum)
- Mobile client hits `/api/v1/auth/login` with email + password
- Receives a personal access token
- Subsequent requests use `Authorization: Bearer <token>`
- Social auth for mobile uses provider SDKs, posts provider token to `/api/v1/auth/social/{provider}` for validation

### 13.5 Session/Cookie vs Token
- Web (Inertia): standard Laravel session cookies, CSRF protected
- Mobile (API): Sanctum tokens only
- Same `User` model, same authentication guards, different drivers

---

## 14. Anti-Abuse Measures

### 14.1 Signup defense
- **Cloudflare Turnstile** on signup form and login (after 3 failures)
- Disposable email domain blocklist (configurable)
- One account per verified email; no email aliasing with `+` tricks
- IP-based signup rate limiting (5 per IP per day configurable)

### 14.2 Action rate limiting
- Server-side per-action cooldowns (e.g. no action faster than 200ms)
- Per-player per-minute caps on each action type (configurable)
- Rolling window via `action_rate_log` table or Redis sliding window

### 14.3 Behavioral flags
- Moves-per-minute anomaly detection
- Perfect-interval action patterns (bot tell)
- Implausible tile sequences (teleporting)
- Flagged accounts go into a review queue in admin panel (not auto-banned)

### 14.4 Reporting
- In-game "Report Player" form for harassment/cheating
- Admin panel review queue with context

### 14.5 Multi-account policy
- Allowed but discouraged
- Hard rule: cannot interact between your own alts (gifts, attacks, MDN sharing)
- Detection via cookies, email similarity, login fingerprint clustering
- Manual enforcement only at launch

---

## 15. Admin Panel (Filament)

Mounted at `/admin`. Gate: `is_admin == true`.

### Sections
- **Players** — search, inspect, freeze, adjust stats/cash/intel (with audit log), reset password
- **Game Settings** — edit any config key, see defaults, audit trail, one-click revert
- **World Tiles** — browse the map grid, inspect any tile, manually place special content
- **Items Catalog** — edit item definitions, prices, effects
- **Sponsor Offers** — manage sponsor bonus offers and webhook secrets
- **Reports** — review player reports
- **Bans** — manage bans
- **RNG Log** — query recent rolls for dispute investigation
- **Leaderboards** — manually trigger re-roll
- **Seasons** — manage season config, preview next season
- **Audit Log** — every admin action timestamped and searchable
- **System Health** — queue depth, Reverb status, Redis health

---

## 16. API Design (`/api/v1/*`)

All responses are JSON following a consistent envelope:

```json
{
  "data": {...},
  "meta": {...},
  "errors": null
}
```

Versioned by URL prefix. Resources:

- `POST /api/v1/auth/register`
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/social/{provider}`
- `POST /api/v1/auth/logout`
- `GET  /api/v1/me`
- `GET  /api/v1/me/state` — player state bundle (stats, cash, moves, position, etc.)
- `GET  /api/v1/map/current-tile`
- `POST /api/v1/map/move` — { direction: n|s|e|w }
- `POST /api/v1/drill` — { grid_x, grid_y }
- `POST /api/v1/spy` — { target_player_id }
- `POST /api/v1/attack` — { target_player_id }
- `GET  /api/v1/posts/{tile_id}/items`
- `POST /api/v1/posts/{tile_id}/purchase` — { item_key, quantity }
- `GET  /api/v1/journal`
- `POST /api/v1/journal` — { tile_id, note }
- `GET  /api/v1/mdn`
- `POST /api/v1/mdn/join`
- `POST /api/v1/mdn/leave`
- `POST /api/v1/mdn/alliance` — { target_mdn_id }
- `GET  /api/v1/leaderboards/{category}`
- `GET  /api/v1/items/{item_key}` — catalog lookup

Rate limited via Laravel's `throttle:api` middleware with configurable limits per route.

Web (Inertia) controllers duplicate the endpoint surface but return Inertia responses with the same underlying service calls.

---

## 17. Frontend Architecture (Vue + Inertia)

### 17.1 Page structure
```
resources/js/
├── Pages/
│   ├── Auth/
│   │   ├── Login.vue
│   │   ├── Register.vue
│   │   └── VerifyEmail.vue
│   ├── Game/
│   │   ├── Map.vue             (main gameplay view)
│   │   ├── Base.vue
│   │   ├── Post.vue
│   │   ├── Attack.vue
│   │   ├── Spy.vue
│   │   └── Journal.vue
│   ├── Mdn/
│   │   ├── Browse.vue
│   │   ├── View.vue
│   │   └── Create.vue
│   ├── Leaderboards/
│   │   └── Index.vue
│   └── Profile/
│       └── Show.vue
├── Layouts/
│   ├── GameLayout.vue          (main shell: header, stats bar, nav, slot)
│   └── AuthLayout.vue
├── Components/
│   ├── TileView.vue            (current tile rendering)
│   ├── DrillGrid.vue           (5x5 drill point picker)
│   ├── StatsBar.vue
│   ├── MovesMeter.vue
│   ├── AttackDialog.vue
│   ├── SpyDialog.vue
│   ├── JournalPanel.vue
│   └── ...
├── Composables/
│   ├── useEcho.ts              (real-time WebSocket subscription)
│   ├── useMoves.ts
│   └── useGameState.ts
├── Stores/                     (Pinia)
│   └── uiStore.ts              (modal state, toasts, etc. only)
└── app.ts
```

### 17.2 Rendering choices
- **Map view:** plain DOM/CSS grid, no canvas. Accessible, simple, fine for turn-based pace
- **Drill grid:** 5×5 CSS grid of clickable cells
- **Tile view:** card layout with flavor text, actions contextual to tile type
- **Real-time:** Echo subscriptions in `GameLayout`, dispatch toasts on events

### 17.3 Server state vs UI state
- Server state flows via Inertia props (reloaded on every page visit)
- Pinia only holds transient UI state: open modals, toast queue, input draft values
- Never cache server state in Pinia — refetch via `Inertia.reload()` when stale

### 17.4 Styling
- Tailwind 3 with a custom theme: dusty ochres, rust reds, oil-slick greens, muted teals
- Custom fonts: display font evoking oil-rig signage, body font monospace-adjacent for that "terminal on a derrick" feel
- Dark-first (it's a grim frontier world)

---

## 18. Deployment (Manual, Debian + DirectAdmin)

### 18.1 Initial server setup (one-time, documented runbook)
1. Ensure Debian stable, DirectAdmin installed
2. Install PHP 8.3 with required extensions: `mbstring`, `xml`, `bcmath`, `intl`, `redis`, `mysql`, `curl`, `zip`, `gd`
3. Install MySQL 8 (or use DirectAdmin-managed MySQL)
4. Install Redis 7: `apt install redis-server`
5. Install Node 20 LTS: via `nvm` or NodeSource
6. Install Composer globally
7. Install Supervisor: `apt install supervisor`
8. Create DirectAdmin user for the site
9. Point domain at the box, get SSL via DirectAdmin's Let's Encrypt
10. Clone repo to user's public_html or to `/home/<user>/cashwars` symlinked
11. Copy `.env.production` from template, fill secrets
12. `composer install --no-dev --optimize-autoloader`
13. `npm ci && npm run build`
14. `php artisan migrate --force`
15. `php artisan db:seed --class=ProductionSeeder`
16. `php artisan storage:link`
17. `php artisan optimize`
18. Configure Supervisor for: `queue:work` (Horizon), `reverb:start`, optional `schedule:work`
19. Configure Nginx custom includes for Reverb WebSocket proxy
20. Test end-to-end

### 18.2 Deploy script (`deploy.sh` on server)
```bash
#!/bin/bash
set -e
cd /home/user/cashwars

php artisan down --render=maintenance
git pull origin main
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan optimize
php artisan horizon:terminate
sudo supervisorctl restart reverb
php artisan up
```

Run as: `bash deploy.sh` after pushing to main.

### 18.3 Backup strategy
- **Nightly `mysqldump`** via cron: `0 3 * * * /home/user/scripts/backup.sh`
- Script dumps DB to `/home/user/backups/cashwars-YYYYMMDD.sql.gz`
- Keeps 14 days locally
- User will handle off-server rotation separately
- Redis is a cache layer — not backed up (can be rebuilt)

### 18.4 Monitoring (minimal, appropriate for scale)
- Horizon dashboard at `/horizon`
- Laravel Telescope at `/telescope` (dev/admin only)
- Basic uptime checks via external service (user's choice)
- Error reporting via Laravel's log channel (file + optional webhook to Discord/Slack)

---

## 19. Testing Strategy

### 19.1 Pest with Laravel plugin
- **Unit tests** for domain services (CombatFormula, DrillService, FogOfWarService, etc.)
- **Feature tests** for HTTP endpoints (both Web and API controllers)
- **Architecture tests** enforcing: no domain logic in controllers, no HTTP concerns in domain
- **Dataset tests** for the combat formula across stat combinations

### 19.2 Coverage priorities
- CombatFormula: near-100% (critical for balance)
- DrillService: near-100%
- FogOfWarService: 90%+
- Controllers: happy path + auth + validation
- Game config fallback chain: explicit test coverage

### 19.3 Deterministic RNG in tests
- `RngService` supports `replay` mode accepting a fixture array of outcomes
- Tests load RNG fixtures from `tests/Fixtures/rng/*.json`
- No flaky tests, all combat outcomes reproducible

### 19.4 Load test at launch
- A simple `k6` or `artillery` script simulating 100 concurrent players performing typical actions
- Validates the single-VPS assumption before opening signups

---

## 20. Open Technical Decisions (for later discussion)

1. **Local dev on Windows** — Laravel Herd (lighter) vs Laravel Sail (Docker, more production-like). I lean **Herd** for Windows since you said the dev machine is Windows and Herd has a Windows version now.
2. **Filament vs custom admin** — Filament is mature and fast to build. Any objection?
3. **Email provider** — Resend (modern, simple, cheap) vs Postmark (transactional king). Either works; I lean **Resend**.
4. **Disposable email blocklist source** — use `disify` API, self-hosted list, or Laravel package?
5. **Asset storage** — at 100 users with procedurally generated avatars, local disk is fine. S3 compatibility via Flysystem is trivial to add later.
6. **Search** — any need for full-text search at v1? I'd say no; MySQL `LIKE` is fine for username lookup.
7. **Scheduler process** — run `php artisan schedule:work` via Supervisor (continuous) or rely on cron `* * * * * php artisan schedule:run`? DirectAdmin cron is reliable; I'd go cron.
8. **Database migrations on deploy** — always `--force` with a pre-deploy backup? I assume yes.

---

## 21. v1 Launch Checklist

- [ ] Laravel 11 project bootstrapped with Inertia + Vue 3 + Tailwind
- [ ] MySQL schema migrated per §6
- [ ] `config/game.php` populated with all default tunables per §4
- [ ] `GameConfig` facade + DB override layer implemented
- [ ] `RngService` with seeded rolls and optional logging
- [ ] `WorldService` with initial generation
- [ ] `FogOfWarService` with discovery tracking
- [ ] `DrillService` with 5×5 grid and quality rolls
- [ ] `CombatFormula` + `SpyService` + `AttackService`
- [ ] `MoveRegenService` lazy reconciliation
- [ ] `IntelService` with earning, spending, decay
- [ ] `MdnService` with membership, alliance, same-MDN rules
- [ ] `JournalService` personal + shared MDN
- [ ] Shop + auction systems
- [ ] Socialite integration for Google / Discord / Apple
- [ ] Email verification flow
- [ ] Sanctum API tokens
- [ ] `/api/v1/*` endpoints for all gameplay
- [ ] Inertia pages for all gameplay views
- [ ] Laravel Reverb running + Nginx WebSocket proxy
- [ ] Horizon running with job catalog
- [ ] Filament admin panel with settings editor
- [ ] Turnstile on signup
- [ ] Rate limiting per action
- [ ] Nightly MySQL backup
- [ ] `deploy.sh` tested end-to-end
- [ ] 100-user load test passed
- [ ] Tutorial NPC dummy base working
- [ ] 48h new-player immunity enforced
- [ ] Leaderboards publishing weekly
- [ ] At least one season configured
- [ ] Documentation: admin runbook, deployment runbook, config reference
