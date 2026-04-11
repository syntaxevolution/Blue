# Clash Wars — Feature Improvements Batch 1

## Context

The user is hardening the pre-launch "Cash Clash" Laravel game (internal codename CashWars) before Phase 3 combat work is fully validated. The existing code is clean, config-driven, Pest-tested, and domain-layered — but the game is missing several pieces the user has decided must land together in one coordinated pass:

1. The user-facing brand is being changed from **Cash Clash** to **Clash Wars** (internal planning docs stay as-is).
2. Combat needs an **at-base defense bonus** (fortification + strength when defender is home, fortification only when away) — this is the first meaningful incentive to actually return to base before moves run out.
3. **Real-time notifications** are missing entirely. Reverb + Echo will be added, plus toasts for attacks/spies/raids, plus a persistent **activity log** (new feature).
4. The dashboard hardcodes "48 hours immunity" and "200 / day" — must come from config. (Also: the 200 vs 350 confusion is a **labelling bug** — `moves.daily_regen=200`, `moves.bank_cap_multiplier=1.75` → bank cap = 350. The dashboard is currently showing the cap but calling it regen.)
5. **Usernames** don't exist as a distinct concept — `users.name` is used but has no format rules, no uniqueness, and is freely editable. Will become a locked, case-insensitively unique handle (alphanumeric, 5–15 chars), claimed once.
6. **Email verification before first login** is not enforced.
7. General store needs **purchasable extra moves**, six **transport modes** (walking default + 5 purchasable), a **teleporter**, and 5 additional flavour items per store.
8. Stat items (`stat_add`) must become **one-purchase-per-item** while their effects still permanently increment stats (current behaviour).
9. The **stat hard cap raises from 25 to 50**. Purchases that would exceed the cap bank the overflow for later cap raises.
10. **Drill items can break** (1% per use, configurable), forcing an immediate repair-or-abandon decision that blocks all other actions. The break system is drill-only for now but must be easy to enable for other item types later.
11. Everything tunable must be a `config/game.php` key and exposed via the existing `GameConfig`/`game_settings` override system (automatically picked up by the Filament admin panel).
12. After implementation, a **PM agent + 2 independent code-reviewer agents** must audit the work and I must fix every finding, looping until clean, no iteration cap.

The intended outcome: a single cohesive delivery covering rename, balance, store expansion, movement economy, broadcasting, UX, tests, and review — with a durable reference document (`feature-improvements-1.md`) written to the project root so the work survives an interrupted session.

---

## Scope Boundaries

**In scope:** All 12 feature areas above, associated config keys, migrations, seeders, events, listeners, broadcasts, Vue components, API + web routes, Pest tests, VPS deployment notes (todo.txt), review loop.

**Out of scope:** MDN formal alliance UI, Phase 4+ work, consumable item framework beyond what's needed for extra-moves and the 5 general store flavour items, any changes to the three ultraplan markdown docs or `CLAUDE.md`.

---

## File Map — New Files

### Backend — Domain
- `app/Domain/Combat/AtBaseBonus.php` — pure helper: `defenderIsHome(Player $d): bool`
- `app/Domain/Economy/ExtraMovesService.php` — grants N moves, can exceed bank cap
- `app/Domain/Economy/TransportService.php` — switch active transport, validate ownership
- `app/Domain/Economy/TeleportService.php` — validate destination exists + barrel cost + teleport
- `app/Domain/Items/ItemBreakService.php` — break roll, repair, abandon; drill-only by config, extensible
- `app/Domain/Items/StatOverflowService.php` — compute applied vs banked stat deltas when purchasing
- `app/Domain/Notifications/ActivityLogService.php` — record + query + mark-read
- `app/Domain/Player/TransportMovementService.php` — multi-tile movement for bicycle+, fog reveal en route, fuel cost

### Backend — Events (all implement `ShouldBroadcast` on private user channel)
- `app/Events/BaseUnderAttack.php`
- `app/Events/SpyDetected.php`
- `app/Events/RaidCompleted.php`
- `app/Events/MdnEvent.php` — scaffolded, used by activity log only for now

### Backend — Listeners
- `app/Listeners/RecordActivityLog.php` — single listener, handles all four events, calls `ActivityLogService::record`

### Backend — Controllers
- `app/Http/Controllers/Web/TransportController.php` — switch active transport
- `app/Http/Controllers/Web/TeleportController.php` — teleport action
- `app/Http/Controllers/Web/ItemBreakController.php` — repair / abandon endpoints
- `app/Http/Controllers/Web/ActivityLogController.php` — index + mark-read
- `app/Http/Controllers/Web/UsernameController.php` — claim-once-on-first-login
- Mirror each under `app/Http/Controllers/Api/V1/` (thin wrappers delegating to same domain services)

### Backend — Requests
- `app/Http/Requests/TransportSwitchRequest.php`
- `app/Http/Requests/TeleportRequest.php`
- `app/Http/Requests/ClaimUsernameRequest.php`
- `app/Http/Requests/TransportMoveRequest.php`
- `app/Http/Requests/RepairItemRequest.php`

### Backend — Rules
- `app/Rules/UniqueUsername.php` — case-insensitive `LOWER()` check, alphanumeric, 5–15 chars

### Backend — Models
- `app/Models/ActivityLog.php`
- `app/Models/PlayerItem.php` — explicit model (currently raw table `player_items`)
- (`player_items` table gains `status` + `broken_at`; existing code uses it as a table only)

### Backend — Migrations
- `add_username_uniqueness_to_users.php` — functional index on `LOWER(name)`, `name_claimed_at` column, email verification columns check
- `create_activity_logs_table.php`
- `add_transport_and_stat_bank_to_players.php` — `active_transport` string default 'walking'; `strength_banked`, `fortification_banked`, `stealth_banked`, `security_banked` unsigned ints default 0; `broken_item_key` nullable string (global action-block)
- `add_status_to_player_items.php` — `status` enum('active','broken') default 'active', `broken_at` nullable timestamp

### Frontend — Vue
- `resources/js/Components/Toast.vue` — individual toast
- `resources/js/Components/ToastContainer.vue` — mount point, animated stack
- `resources/js/Components/TransportSwitcher.vue` — dropdown with lock icon when no non-default owned
- `resources/js/Components/BrokenItemModal.vue` — repair/abandon modal, blocks UI
- `resources/js/Components/TeleportModal.vue` — coord input + validation preview
- `resources/js/Components/ClaimUsernameModal.vue` — one-time claim flow
- `resources/js/Composables/useEcho.js` — initialises Echo client
- `resources/js/Composables/useNotifications.js` — subscribes private channel, converts events to toasts + activity log refresh
- `resources/js/Pages/ActivityLog.vue` — full activity log page, paginated, mark-read on view

### Frontend — Other
- `resources/js/echo.js` — Echo config

### Root / Ops
- `feature-improvements-1.md` — permanent static reference copy of this plan, written to project root as the first action after plan approval (mirrors the plan file so the user can reference it if interrupted)
- `todo.txt` — appended with VPS / Supervisor / Nginx instructions for Reverb

---

## File Map — Modified Files

### Config
- `config/game.php` — many new keys (see Config Keys section)
- `config/app.php` — `name` default "Clash Wars"
- `config/broadcasting.php` — created by `artisan install:broadcasting` (verify Reverb default)
- `.env.example` — add Reverb keys, APP_NAME="Clash Wars"
- `bootstrap/app.php` — register broadcasting routes (`withBroadcasting(__DIR__.'/../routes/channels.php')`)
- `routes/channels.php` — define `App.Models.User.{id}` private channel
- `routes/web.php` — new routes for transport, teleport, activity log, username claim, item break
- `routes/api.php` — mirror new routes under `/api/v1/*`

### Backend — Existing files to modify
- `app/Domain/Combat/CombatFormula.php:53-92` — accept `$defenderAtBase` param, add strength to defense when true
- `app/Domain/Combat/AttackService.php:64-111` — compute `$defenderAtBase`, fire `BaseUnderAttack` + `RaidCompleted`, check `broken_item_key` guard
- `app/Domain/Combat/SpyService.php:81-90` — roll detection, set `SpyAttempt.detected`, fire `SpyDetected` when detected
- `app/Domain/Drilling/DrillService.php:172-197` — call `ItemBreakService::rollBreak` after yield, set `broken_item_key` on player if broken
- `app/Domain/Economy/ShopService.php:195-224` — enforce one-purchase-per-item for `stat_add`; route `stat_add` through `StatOverflowService`; handle new effect types: `grant_moves`, `unlocks_transport`, `unlocks_teleport`, `raise_daily_drill_limit`, `reduce_break_chance`, `feature_unlock`
- `app/Domain/Player/TravelService.php:68-78` — delegate multi-tile moves to `TransportMovementService` when active transport != walking; block all moves when `broken_item_key` is set
- `app/Domain/Player/MapStateBuilder.php:112-134,382` — include transport list, active transport, broken item state, unread activity count in payload; drain stat banks if cap raised
- `app/Models/Player.php` — fillable additions, `activeTransport()` accessor, `hasBrokenItem()` helper
- `app/Models/User.php` — `name_claimed_at` fillable, `MustVerifyEmail` interface check
- `app/Models/SpyAttempt.php` — no schema change, but `detected` now used
- `database/seeders/ItemsCatalogSeeder.php` — +5 items per store + transport items + teleporter + extra_moves + (flavour items)
- `app/Http/Requests/ProfileUpdateRequest.php` — remove `name` from validated fields (immutable), keep `email`
- `app/Http/Controllers/Auth/RegisteredUserController.php` — validate username rules; `name_claimed_at` set here when provided at registration, else deferred to first login
- `app/Http/Controllers/Auth/VerifyEmailController.php` — ensure existing Breeze flow is sufficient; add middleware route gate for unverified users
- `app/Http/Middleware/HandleInertiaRequests.php` — share `auth.user`, unread activity count, echo config, feature flags
- `app/Providers/AppServiceProvider.php` — (if needed) bind event listeners

### Frontend — Existing files to modify
- `resources/js/app.js` — import `echo.js`, register Echo globally
- `resources/js/Layouts/AuthenticatedLayout.vue` — mount `<ToastContainer/>`, add Activity Log nav link with unread badge, handle broken-item modal overlay
- `resources/js/Pages/Dashboard.vue:16-58` — replace hardcoded values with Inertia props: `starting_cash`, `daily_regen`, `bank_cap`, `immunity_hours`. Fix the "200/day" vs "350 cap" labelling.
- `resources/js/Pages/Map.vue:181-184,264,329-341` — render TransportSwitcher, Teleport button (if owned), BrokenItemModal overlay, ClaimUsernameModal (if not claimed), flash → toasts, dynamic immunity message
- `resources/js/Pages/Welcome.vue`, `Atlas.vue`, `AttackLog.vue`, `resources/js/Layouts/GuestLayout.vue`, `resources/js/Components/ApplicationLogo.vue`, `resources/views/app.blade.php` — "Cash Clash" → "Clash Wars" (user-facing only)

---

## Feature Implementations

### F1 — Brand rename (in-game only)
- `config/app.php` default `name` → `"Clash Wars"`
- `.env.example` → `APP_NAME="Clash Wars"` (note to user: update `.env` on VPS too)
- Grep all Vue and Blade for user-visible "Cash Clash" strings, replace with "Clash Wars"
- **Untouched:** `ultraplan.md`, `gameplay-ultraplan.md`, `technical-ultraplan.md`, `CLAUDE.md`, `config/game.php` comment, any code-level comments referencing CashWars

### F2 — At-base defense bonus
- `config/game.php`: `combat.at_base_defense_bonus_enabled` → true
- `CombatFormula::resolveAttack()` signature: add `bool $defenderAtBase = false`
- When true: `defPower = scaledStat(fortification) + scaledStat(strength)`
- When false: `defPower = scaledStat(fortification)` (current behaviour)
- `AttackService`: `$defenderAtBase = $defender->current_tile_id === $defender->base_tile_id;` — pass to formula
- Update `CombatFormulaTest` + new `AtBaseBonusTest` covering: defender home, defender away, bonus disabled via config

### F3 — Real-time notifications (Reverb + Echo + toasts)

**Install phase (one-shot, during implementation):**
- `composer require laravel/reverb`
- `php artisan install:broadcasting` (creates `config/reverb.php`, `config/broadcasting.php`, `routes/channels.php`, installs `laravel-echo` + `pusher-js` via npm)
- Defaults point to Reverb

**Channel:** Private `App.Models.User.{id}` — authorised via `routes/channels.php`:
```php
Broadcast::channel('App.Models.User.{userId}', fn ($user, $userId) => (int) $user->id === (int) $userId);
```

**Events:** Each extends `Illuminate\Broadcasting\PrivateChannel` on target user's channel. Payload: `{type, title, body, timestamp}`. Non-broadcast fields needed by listeners are passed via constructor.

- `BaseUnderAttack` — fired from `AttackService::attack()` immediately after transaction commit, targets defender user
- `SpyDetected` — fired from `SpyService::attemptSpy()` when detection roll succeeds, targets defender user
- `RaidCompleted` — fired from `AttackService::attack()` alongside `BaseUnderAttack`, carries outcome + loot for the defender's record
- `MdnEvent` — scaffolded class, not fired yet, registered for future use

**Listener:** `RecordActivityLog` subscribes to all four via `EventServiceProvider` (or auto-discover), writes to `activity_logs`.

**Activity log table:**
```
id bigIncrements
user_id fk users (cascade)
type string (index) -- 'attack.incoming', 'spy.detected', 'raid.completed', 'mdn.*'
title string
body json
read_at timestamp nullable
created_at timestamp (index desc)
```

**Frontend wiring:**
- `resources/js/echo.js` — `import Echo from 'laravel-echo'; import Pusher from 'pusher-js'; window.Pusher = Pusher; export default new Echo({broadcaster:'reverb', key: import.meta.env.VITE_REVERB_APP_KEY, wsHost: import.meta.env.VITE_REVERB_HOST, wsPort: import.meta.env.VITE_REVERB_PORT, wssPort: import.meta.env.VITE_REVERB_PORT, forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https', enabledTransports:['ws','wss']});`
- `useNotifications` composable subscribes to `private-App.Models.User.{id}`, listens for `.BaseUnderAttack`, `.SpyDetected`, `.RaidCompleted`, pushes toasts and increments unread-activity store
- `ToastContainer` mounted in `AuthenticatedLayout`
- Unread badge in nav, fetched once on layout mount via `/api/v1/activity?unread=1`

### F4 — Dashboard fixes
`Dashboard.vue:16-58`: replace hardcoded `A5.00`, `~200 / day`, `48 hours` with props from controller:
- `starting_cash` ← `GameConfig::get('new_player.starting_cash')`
- `daily_regen` ← `GameConfig::get('moves.daily_regen')`
- `bank_cap` ← `daily_regen * GameConfig::get('moves.bank_cap_multiplier')` (floor)
- `immunity_hours` ← `GameConfig::get('new_player.immunity_hours')`

**Label fix:** Currently shows "~200 / day" in a box labelled move regen — that's correct (200 is regen). But somewhere the user is seeing "350" — that's the bank cap. Action: make Dashboard show *both* cleanly: "Regen: N/day · Bank cap: M". No hidden magic.

Also fix `Map.vue:329` static immunity phrasing → use `immunity_hours` prop.

### F5 — Usernames

**Rules:**
- Regex: `^[a-zA-Z0-9]{5,15}$`
- Stored as typed (preserves case)
- Uniqueness checked case-insensitively via `whereRaw('LOWER(name) = ?', [strtolower($input)])`
- Once `name_claimed_at` is set, `name` is immutable

**Migration:**
```php
Schema::table('users', function (Blueprint $t) {
    $t->timestamp('name_claimed_at')->nullable()->after('name');
});
// Functional index on LOWER(name) — MySQL 8 supports this directly:
DB::statement('ALTER TABLE users ADD UNIQUE INDEX users_name_lower_unique ((LOWER(name)))');
```

**UniqueUsername rule:** validates format + `whereRaw('LOWER(name) = ?', [...])` not exists

**Registration:** `RegisteredUserController::store` — if `name` passes rules and is unique, set `name_claimed_at = now()` on create. Otherwise reject.

**Existing users (factories / tests only):** no migration backfill needed. Test factories will set `name_claimed_at => now()` to avoid interfering with game flow.

**First-login claim modal:** If `name_claimed_at` is null when loading any authenticated route, Inertia shared prop `requires_username_claim = true` triggers `ClaimUsernameModal.vue` in the layout. All actions other than POST `/username/claim` return 403 until claimed. Middleware: `RequireClaimedUsername`.

**Profile page:** `ProfileUpdateRequest` strips `name`. Blade/Vue profile form shows name as read-only with "cannot be changed" caption. Email remains editable (see F6).

### F6 — Email verification before first login

- `User` implements `MustVerifyEmail` (Laravel contract) → Laravel auto-sends verification email on register via Breeze
- All game routes (web + api v1) gated by `verified` middleware → redirects unverified users to verification notice page
- Login is allowed (required, since verification link requires being logged in), but gameplay locked
- Email updates: profile `email` update triggers re-verification: `$user->email_verified_at = null; $user->sendEmailVerificationNotification();` — existing Breeze behaviour when `User` implements `MustVerifyEmail`
- Verify existing Breeze `VerifyEmailController` + `EmailVerificationPromptController` are wired; if not, enable

### F7 — Stat cap raise 25 → 50 + banking

- `config/game.php`:
  - `stats.hard_cap` → 50
  - `stats.scaling.prestige_range` → [21, 50] (extends 0.3 efficiency band up to 50)
  - keep `linear_range`, `partial_range` as-is
- Players: new columns `strength_banked`, `fortification_banked`, `stealth_banked`, `security_banked` — unsigned int default 0
- `StatOverflowService::apply(Player $p, string $stat, int $delta)`:
  - `current = $p->{$stat}`
  - `cap = GameConfig::get('stats.hard_cap')`
  - `room = max(0, cap - current)`
  - `applied = min($delta, $room)`
  - `banked = $delta - $applied`
  - `$p->{$stat} += $applied; $p->{$stat.'_banked'} += $banked;`
- On every map state load (`MapStateBuilder`), call `drainBank(Player $p)`:
  - For each stat: if cap raised and banked > 0, move as much as fits into the live stat
- Shop purchase of stat items routes through `StatOverflowService` — never blocks on cap, always banks excess

### F8 — One purchase per stat item
- `ShopService::purchase`: if item has `stat_add` effect AND `PlayerItem::where(player_id, item_key)->exists()` → throw `CannotPurchaseException::alreadyOwned()`
- Test case: buy rock once, buy rock again → rejected
- Drill items (`set_drill_tier`), consumables (`grant_moves`, etc.), transport (`unlocks_transport`), teleporter (`unlocks_teleport`), feature unlocks (`unlocks`) have their own rules (see per-item below)

### F9 — Extra moves pack (general store)
- New item: `extra_moves_pack`, post_type `general`, price 1000 barrels, effect `{"grant_moves": 10}`
- **Not** tracked in `player_items` (or tracked with quantity++ — cosmetic, doesn't affect gameplay since it's purely consumable)
- Decision: track quantity++ for stats/history but effect applies on purchase
- `ShopService` handles `grant_moves`: `$player->moves_current += $amount;` — NO cap clamp (can exceed bank cap per user spec)
- Unlimited purchases per day (no cooldown)
- Config: `general_store.extra_moves.cost_barrels` (1000), `.amount` (10)

### F10 — Transport modes

**Ownership model:** Purchased transports go in `player_items` like any other item. Walking is implicit (always owned). Player has `active_transport` column (default `'walking'`).

**Items seeded:**
| key | post | cost | spaces | fuel/press | special |
|---|---|---|---|---|---|
| `bicycle` | general | 500 | 2 | 0 | — |
| `motorcycle` | general | 1500 | 5 | 1 | — |
| `sand_runner` | general | 5000 | 10 | 2 | reveals 4 cardinal neighbours of destination |
| `helicopter` | general | 25000 | 25 | 5 | — |
| `airplane` | general | 100000 | 50 | 10 | reveals all intermediate tiles |

Effect type: `{"unlocks_transport": "bicycle", "spaces": 2, "fuel": 0, "flags": [...]}`  
Purchase applies: inserts `player_items` row + (doesn't set active, player must switch manually).

**Switch:** `POST /map/transport` with `{transport: 'motorcycle'}` — validates player owns item (`player_items` row exists + status=active) OR transport=='walking' (always allowed). Updates `player.active_transport`.

**Move mechanics (`TransportMovementService`):**
- Input: direction (N/S/E/W), player's active transport config
- `spaces = transport.spaces; fuel = transport.fuel` (flat per press)
- Pre-check: `player.moves_current >= 1` (flat 1 move per press regardless of transport)
- Pre-check: `player.oil_barrels >= fuel`
- Pre-check: walk the vector, verify every intermediate + destination tile exists. If any doesn't → throw `CannotTravelException::edgeOfWorld(x,y)` — trip never starts, nothing charged
- Deduct: 1 move, fuel barrels
- Update `current_tile_id` to destination
- Fog reveal:
  - Walking (1 space): destination only (current behaviour)
  - Bicycle/motorcycle/helicopter: destination only (fast but no bonus reveal)
  - Sand Runner: destination + 4 cardinal neighbours
  - Airplane: every intermediate tile + destination
- Fires no combat events, purely movement

**UI switcher (`TransportSwitcher.vue`):**
- Rendered in `Map.vue` control panel
- If player owns no non-walking transport: button disabled, lock icon overlay, tooltip "Unlock transport in the General Store"
- If owned: dropdown listing walking + all owned transports, current highlighted
- Switch triggers `router.post('/map/transport', {transport})` via Inertia
- Active transport shown in movement panel as "Travelling by: {name} ({spaces} spaces, {fuel}⛽)"

**Config keys (all tunable):**
- `general_store.transport.bicycle.cost_barrels`, `.spaces`, `.fuel`
- `general_store.transport.motorcycle.*`
- `general_store.transport.sand_runner.*`
- `general_store.transport.helicopter.*`
- `general_store.transport.airplane.*`

### F11 — Teleporter

- Item `teleporter`, post_type `general`, cost 250000 barrels, effect `{"unlocks_teleport": true}`
- One-time purchase (enforced by `stat_add`-style owned check, but for teleport it's a feature unlock)
- `TeleportService::teleport(Player $p, int $x, int $y)`:
  1. `if (!$p->hasTeleporter()) throw`
  2. `$tile = Tile::where(['x'=>$x,'y'=>$y])->first();`
  3. `if (!$tile) throw CannotTravelException::edgeOfWorld($x,$y)` — **no barrel charge**
  4. `$cost = GameConfig::get('teleport.cost_barrels')` (5000)
  5. `if ($p->oil_barrels < $cost) throw CannotPurchaseException::insufficientBarrels()`
  6. Transaction: deduct barrels, update `current_tile_id`, reveal destination tile fog
- Route: `POST /map/teleport` + `/api/v1/map/teleport`
- UI: `TeleportModal.vue` — x/y inputs, "Validate" button (calls `GET /map/tile-exists?x=&y=` — new read-only endpoint), then "Teleport (5000⛽)" button. Shows red "Destination does not exist" if invalid before charging.
- Config: `teleport.enabled` (true), `teleport.cost_barrels` (5000), `teleport.purchase_cost_barrels` (250000)

### F12 — Drill break + repair + abandon

**Break roll:** `DrillService::drill()` — after yield computation, before saving:
```php
if ($this->itemBreakService->shouldRoll($player->activeDrillItemKey())) {
    if ($this->rng->rollFloat() < GameConfig::get('drilling.break_chance_pct')) {
        $this->itemBreakService->markBroken($player, $player->activeDrillItemKey());
    }
}
```
- Tier 1 (Dentist Drill) is **excluded** — user: "except for the default that everyone joins the game with"
- `shouldRoll` checks a per-item-type config allowlist: `drilling.break_item_types` → `['set_drill_tier']`
- Exclude starter drill by config: `drilling.break_excluded_keys` → `['dentist_drill']` (no such seed key currently; would be the implicit tier 1 — handled by checking `player->drill_tier > 1` since dentist drill is implicit and never in `player_items`)
- Actually: drill items in `player_items` for tier 2+ only. Only owned drill items can break. Starter drill is never in `player_items`, so it's automatically excluded. No extra config needed.

**Broken state:** `player_items.status = 'broken'`, `player_items.broken_at = now()`, `players.broken_item_key = <key>`

**Global action block:** New middleware `BlockOnBrokenItem` applied to all game action routes (move, drill, spy, attack, purchase, transport, teleport, travel). If `$player->broken_item_key !== null`, return 423 Locked with payload `{broken_item_key}`.
- Exception: repair + abandon endpoints are allowed
- Inertia: server shares `broken_item_key` as Inertia prop → `BrokenItemModal.vue` overlays entire UI

**Modal:** shows item name, "Repair for X barrels" vs "Abandon" buttons
- Repair cost: `ceil(item.price_barrels * 0.10)` per config `items.break.repair_cost_pct` (0.10)
- Repair: deduct barrels, `status=active`, `broken_at=null`, `players.broken_item_key=null`
- If barrels insufficient: repair button disabled, only abandon possible → **forced abandon** (user spec)
- Abandon: delete `player_items` row. Recompute `drill_tier` = max tier among remaining owned drills with `status=active`, or 1 (starter) if none. Clear `broken_item_key`.

**Routes:** `POST /items/repair`, `POST /items/abandon` + api v1 mirrors

**Config:**
- `items.break.enabled` → true
- `items.break.eligible_effect_keys` → `['set_drill_tier']`
- `drilling.break_chance_pct` → 0.01
- `items.break.repair_cost_pct` → 0.10

### F13 — Additional items (5 per store)

**Strength post (5 new):**
1. `rusty_chain` — +5 str, 140⛽, "Previously attached to a bicycle. Now it's a weapon."
2. `sledgehammer` — +6 str, 250⛽, "Measures damage in units of 'ow'."
3. `lead_pipe` — +7 str, 420⛽, "Clue™ not included."
4. `spiked_gauntlet` — +8 str, 700⛽, "Has one setting: aggressive."
5. `surplus_minigun` — +10 str, 1400⛽, "Found in a crate labelled 'PLEASE RETURN'."

**Stealth post (5 new):**
1. `scented_oils` — +5 stealth, 140⛽, "You smell like the desert. The desert smells like nothing."
2. `whisper_boots` — +6 stealth, 250⛽, "Silent. Also slightly too tight."
3. `camo_tarp` — +7 stealth, 420⛽, "Works great unless you're moving."
4. `distraction_duck` — +8 stealth, 700⛽, "Guards watch it. You watch them watch it."
5. `void_cowl` — +10 stealth, 1400⛽, "Makes you slightly less visible. Also makes you mildly sad."

**Fort post (5 new — mix of fort + security):**
1. `sandbag_wall` — +5 fort, 140⛽, "Pre-filled. Mostly with sand."
2. `concrete_moat` — +6 fort, 250⛽, "Water optional."
3. `motion_sensor_array` — +4 sec, 180⛽, "Triggers on squirrels. And wind. And squirrels."
4. `laser_grid` — +7 fort, 420⛽, "Upgraded from a supermarket produce mister."
5. `autocannon_turret` — +8 fort, 900⛽, "Has a customer service hotline. Do not call it."

**Tech post (5 new — non-drill utility):**
1. `spare_drill_bit` — consumable, prevents next break, 150⛽, "Pre-sharpened regret."
2. `emergency_repair_kit` — consumable, free repair of one broken drill, 200⛽
3. `oil_diviner` — unlocks preview of drill-point quality, 500⛽, "A stick. A very confident stick."
4. `field_journal` — +1 to daily drill limit per field (passive), 300⛽
5. `lucky_coin` — −0.5% break chance (passive, stacks once), 400⛽

**General post (5 new flavour, plus separately the 5 transport + teleporter + extra_moves):**
1. `rumor_pamphlet` — consumable, reveals 3 nearest undiscovered oil fields in a 5-tile radius, 80⛽
2. `emergency_ration` — consumable, +20 moves immediately (like a mini extra-moves pack), 150⛽
3. `compass_plus` — unlocks bigger fog-reveal radius (2), 600⛽
4. `map_fragments` — consumable, reveals a 5×5 area around current tile, 200⛽
5. `lucky_charm` — passive, +5% drill yield bonus, 800⛽

**New effect types introduced:**
- `grant_moves` (F9, also used by emergency_ration)
- `unlocks_transport` + associated {spaces,fuel,flags}
- `unlocks_teleport`
- `preview_drill_quality` (feature flag via existing `unlocks` array)
- `daily_drill_limit_bonus` (passive bonus applied in `DrillService`)
- `break_chance_reduction_pct` (passive, applied in `ItemBreakService`)
- `drill_yield_bonus_pct` (passive, applied in `DrillService::computeYield`)
- `reveal_radius_bonus` (passive, applied in `FogOfWarService`)
- `reveal_area` (consumable, called on purchase)
- `reveal_nearest_oil_fields` (consumable)
- `prevent_next_break` (passive flag on `player_items` row for the drill — "shielded" column OR stored on players as `next_break_shielded`)

All new effect types are handled in `ShopService::applyEffects()` with explicit branches. Each is covered by a test.

### F14 — Config keys (complete new list)

```php
// config/game.php additions

'combat' => [
    // existing keys...
    'at_base_defense_bonus_enabled' => true,
    'spy' => [
        // existing...
        'detection_chance_base' => 0.20,
        'detection_per_security_diff' => 0.02,
        'detection_chance_min' => 0.02,
        'detection_chance_max' => 0.95,
    ],
],

'stats' => [
    'hard_cap' => 50, // was 25
    'scaling' => [
        // linear + partial unchanged
        'prestige_range' => [21, 50], // was [21, 25]
    ],
],

'moves' => [
    // existing...
    'allow_overflow_from_purchases' => true,
],

'general_store' => [
    'extra_moves' => [
        'enabled' => true,
        'cost_barrels' => 1000,
        'amount' => 10,
    ],
    'transport' => [
        'bicycle'     => ['cost_barrels'=>500,    'spaces'=>2,  'fuel'=>0,  'flags'=>[]],
        'motorcycle'  => ['cost_barrels'=>1500,   'spaces'=>5,  'fuel'=>1,  'flags'=>[]],
        'sand_runner' => ['cost_barrels'=>5000,   'spaces'=>10, 'fuel'=>2,  'flags'=>['reveal_cardinal_neighbours']],
        'helicopter'  => ['cost_barrels'=>25000,  'spaces'=>25, 'fuel'=>5,  'flags'=>[]],
        'airplane'    => ['cost_barrels'=>100000, 'spaces'=>50, 'fuel'=>10, 'flags'=>['reveal_path']],
    ],
],

'teleport' => [
    'enabled' => true,
    'purchase_cost_barrels' => 250000,
    'cost_barrels' => 5000,
],

'drilling' => [
    // existing...
    'break_chance_pct' => 0.01,
    'daily_limit_base' => 5, // was implicit
],

'items' => [
    'break' => [
        'enabled' => true,
        'eligible_effect_keys' => ['set_drill_tier'],
        'repair_cost_pct' => 0.10,
    ],
    'stat_items_single_purchase' => true,
],

'notifications' => [
    'broadcast_enabled' => true,
    'activity_log_retention_days' => 90,
],

'new_player' => [
    // existing keys...
    'require_email_verification' => true,
],
```

All keys picked up automatically by `GameConfigResolver` → Filament admin via existing `GameSettingResource` (key-value table, no schema change needed).

### F15 — VPS / Supervisor notes (`todo.txt`)

Create `todo.txt` at project root with a Reverb deployment section. Content:

```
# Clash Wars — VPS setup TODO (after this code is pulled)

## 1. Install dependencies
cd /path/to/clashwars
composer install --no-dev --optimize-autoloader
npm ci && npm run build

## 2. Migrations
php artisan migrate --force

## 3. Environment
Edit .env on the VPS:
  APP_NAME="Clash Wars"
  BROADCAST_CONNECTION=reverb
  REVERB_APP_ID=<generate: openssl rand -hex 8>
  REVERB_APP_KEY=<generate: openssl rand -hex 16>
  REVERB_APP_SECRET=<generate: openssl rand -hex 24>
  REVERB_HOST="0.0.0.0"
  REVERB_PORT=8080
  REVERB_SCHEME=http

  VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
  VITE_REVERB_HOST="your.domain.tld"
  VITE_REVERB_PORT=443
  VITE_REVERB_SCHEME=https

Then rebuild assets: npm run build

## 4. Nginx reverse proxy (add to your site's server block)
location /app/ {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header X-Forwarded-For $remote_addr;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 60m;
}
sudo nginx -t && sudo systemctl reload nginx

## 5. Supervisor — Reverb worker
sudo nano /etc/supervisor/conf.d/clashwars-reverb.conf

[program:clashwars-reverb]
process_name=%(program_name)s
command=php /path/to/clashwars/artisan reverb:start --host=0.0.0.0 --port=8080
autostart=true
autorestart=true
user=<your deploy user>
redirect_stderr=true
stdout_logfile=/path/to/clashwars/storage/logs/reverb.log
stopwaitsecs=3600

## 6. Supervisor — queue worker (for broadcast dispatching)
sudo nano /etc/supervisor/conf.d/clashwars-queue.conf

[program:clashwars-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/clashwars/artisan queue:work --tries=3 --timeout=90
autostart=true
autorestart=true
user=<your deploy user>
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/clashwars/storage/logs/queue.log
stopwaitsecs=3600

## 7. Reload supervisor
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start clashwars-reverb:*
sudo supervisorctl start clashwars-queue:*

## 8. Firewall
# Only open 8080 if NOT using nginx reverse proxy. Recommended: keep 8080 private, proxy via nginx on 443.
# sudo ufw allow 8080   # only if needed

## 9. Verify
- Open the game in browser, log in
- Check browser console: should see "Echo connected"
- Have another player attack your base → toast should appear
- tail -f storage/logs/reverb.log — should see connection log
```

### F16 — Test coverage (Pest)

New/updated test files:

**Unit:**
- `tests/Unit/AtBaseBonusTest.php` — CombatFormula with defender home/away/disabled
- `tests/Unit/StatOverflowServiceTest.php` — apply, bank, drain on cap raise
- `tests/Unit/ItemBreakServiceTest.php` — shouldRoll, markBroken, repair, abandon, tier fallback
- `tests/Unit/UniqueUsernameRuleTest.php` — case-insensitive, format bounds
- `tests/Unit/TransportConfigTest.php` — all transport configs resolvable

**Feature:**
- `tests/Feature/BrandingTest.php` — sweep grep for residual "Cash Clash" in served views, assert "Clash Wars" in title
- `tests/Feature/UsernameClaimFlowTest.php` — register → claim → cannot re-claim
- `tests/Feature/EmailVerificationGateTest.php` — unverified cannot access /map
- `tests/Feature/StatCapStackingTest.php` — buy rock once (OK), twice (rejected)
- `tests/Feature/ExtraMovesPurchaseTest.php` — unlimited, overflows cap
- `tests/Feature/TransportMovementTest.php` — bicycle 2 tiles OK, motorcycle insufficient fuel → fails, airplane path reveal
- `tests/Feature/TeleportTest.php` — invalid coords (no charge), valid (charge), no teleporter (reject)
- `tests/Feature/DrillBreakFlowTest.php` — seeded RNG forces break → repair → action allowed; alt → abandon → tier drop
- `tests/Feature/AttackIncomingBroadcastTest.php` — `Event::fake([BaseUnderAttack::class])`, verify dispatched
- `tests/Feature/SpyDetectionTest.php` — forced detection → event + activity log row
- `tests/Feature/ActivityLogApiTest.php` — list, mark-read
- `tests/Feature/BrokenItemBlockTest.php` — all game routes 423 while broken
- `tests/Feature/DashboardConfigDrivenTest.php` — change config, dashboard reflects
- Update existing `CombatFormulaTest.php`, `AttackServiceTest.php`, `TravelTest.php`, `ProfileTest.php`

### F17 — Review loop (post-implementation, mandatory)

After all code is written and `php artisan test` is green, launch the review loop:

1. **PM agent (general-purpose)** — passed the original user message + the final plan. Task: verify every bullet in the user's requirements list is implemented. Returns a punch list of missing/incomplete items.
2. **Code-reviewer agent #1 (feature-dev:code-reviewer)** — scope: all new and modified files. Focus: logic bugs, race conditions, security (auth on new routes, SQL injection on `whereRaw`, broadcast authorisation), Pest test coverage gaps, convention adherence.
3. **Code-reviewer agent #2 (feature-dev:code-reviewer, independent)** — same scope, same brief, no cross-pollination. Catches what #1 misses.
4. Aggregate findings → TaskCreate per finding → fix → re-run tests → if fixes were non-trivial, re-run the 2 reviewers on the diff only. Loop until both return clean (no iteration cap).
5. Final message to user: summary of what shipped, links to `feature-improvements-1.md` and `todo.txt`, note anything deferred (shouldn't be anything).

Agents run in parallel where possible (PM + both reviewers simultaneously in step 1–3).

---

## Migration order (idempotent, single batch)

1. `2026_04_11_000001_rename_app_branding.php` — no-op placeholder for history (actual changes in config)
2. `2026_04_11_000002_add_username_uniqueness_to_users.php` — `name_claimed_at`, functional unique index
3. `2026_04_11_000003_create_activity_logs_table.php`
4. `2026_04_11_000004_add_transport_and_stat_bank_to_players.php` — `active_transport`, `*_banked`, `broken_item_key`
5. `2026_04_11_000005_add_status_to_player_items.php` — `status`, `broken_at`

All reversible (`down()` provided).

---

## Integration points / cross-feature interactions

- **Broken item block** pre-empts **transport moves**, **teleport**, **extra moves purchase**, **shop**, **attack**, **spy**, **drill** — middleware layer, not service-by-service (single source of truth)
- **Stat cap raise + banking** interacts with **one-purchase-per-item** — order: own-check first, then overflow-bank. You can't buy a rock twice even if banked room exists.
- **Transport pre-check** interacts with **edge-of-world** — every intermediate tile validated before any state mutation
- **Airplane fog reveal** interacts with **FogOfWarService** — need `discoverMany(array $tiles)` method (or loop existing `discover()`)
- **Spy detection** interacts with **activity log** — detection fires broadcast *and* listener persists — defender sees toast + persistent log entry
- **Email verification** gates all other game routes — middleware order matters: `auth` → `verified` → `RequireClaimedUsername` → `BlockOnBrokenItem` → route
- **Username claim** happens after email verification but before any game action
- **Dashboard** is public after login (no game routes) but also shows config — verify immunity/regen shown match what the player will actually get

---

## Verification — end-to-end test plan

After implementation completes, I will run locally:

1. **Unit + feature tests**: `php artisan test` — all green
2. **Manual sanity** (describe, not execute — user tests on VPS per their workflow):
   - Register → receive verification email stub (log driver locally) → click → claim username modal → enter "TestUser" → accepted
   - Dashboard shows "Clash Wars" title, immunity pulls from config, regen label correct
   - Buy a rock → strength +1, try again → rejected
   - Buy bicycle, switch to it on map, move 2 tiles → uses 1 move
   - Buy motorcycle, move 5 tiles without enough fuel → rejected, no state change
   - Buy airplane, move 50 tiles across map → path revealed in fog
   - Buy teleporter, teleport to (999,999) → rejected, no charge; teleport to valid coords → 5000⛽ deducted
   - Buy heavy drill tier 4, drill repeatedly with RNG forcing break → modal blocks UI, repair → resumes; alternate branch: abandon → drill_tier drops to next-highest owned
   - Have player B attack player A → player A's browser shows toast + activity log entry
3. **Branding sweep**: `grep -R "Cash Clash" resources/ config/ public/` → only appears in commented code (if at all), never in user-visible output
4. **Config sanity**: `GameConfig::get('combat.at_base_defense_bonus_enabled')` returns true; flipping via `game_settings` table flips behaviour without deploy
5. **Broadcast sanity**: `BROADCAST_CONNECTION=log` locally, tail `storage/logs/laravel.log`, perform attack, verify serialized event logged on correct channel

User will manually verify step 2 on the VPS per the established workflow (local code, remote test).

---

## Plan artifact persistence

Immediately after plan approval and entry to execution mode, the **first action** is to copy this plan's content verbatim to `C:\Users\PC\Documents\Projects\Blue Tin Sultana\feature-improvements-1.md` — per user request for a durable in-repo reference that survives session interruption. The system plan file (`mutable-swinging-sprout.md`) will also be kept in sync as a mirror.

---

## What this plan explicitly does NOT do

- Does not touch `ultraplan.md`, `gameplay-ultraplan.md`, `technical-ultraplan.md`, or `CLAUDE.md`
- Does not implement MDN alliance UI beyond scaffolding the event class
- Does not add consumable-item infrastructure beyond what the 5 new general items require
- Does not change move regen rate or daily limit (200/day stays)
- Does not commit to git (user commits manually — per memory)
- Does not touch the VPS — only produces `todo.txt` with instructions for the user to run
