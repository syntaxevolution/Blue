# Cash Clash — Improvements Phase II

## Context

Three gaps were flagged after Phase I shipped:

1. **MDNs are absent.** Phase 4 (Social) of the master roadmap was never started. `players.mdn_id` / `mdn_joined_at` / `mdn_left_at` columns exist and `config/game.php` has an `mdn` block, but there are zero tables, models, services, routes, pages, or admin resources. `MdnEvent` exists as a dead scaffold, and `app/Domain/Mdn/` is a `.gitkeep`-only directory. Same-MDN attack/spy blocking is not enforced in `AttackService` or `SpyService`. The game advertises alliances but can't deliver them.
2. **No bots.** The project needs background AI players so a 100-user launch isn't an empty ghost town. Bots must follow the exact same rules as humans (domain services, RNG, GameConfig), be spawnable/destroyable/reconfigurable via Artisan, run autonomously on the Laravel scheduler, and optimize primarily for **Akzar Cash**, with strategy varying by difficulty tier.
3. **Spawn safety.** Confirm new players (and new bots) cannot accidentally plant a base on a non-playable tile.

### Decisions locked (from clarifying questions)

- **MDN scope:** Full Phase 4 — create/join/leave/kick/promote/disband, same-MDN attack+spy blocking, 24h hop cooldown, formal alliances (declarative UI), shared journal with voting, Filament admin, Vue pages, full Pest tests.
- **Bot difficulty:** Three tiers — `easy`, `normal`, `hard`. All three ultimately optimize for Akzar Cash; difficulty changes risk appetite, action mix, and target selection.
- **Bot runtime:** Laravel scheduler runs `bots:tick` every N minutes (N is a config key), no queue/Horizon dependency.
- **Bot identity:** Synthetic emails (`bot-{uuid}@bots.cashclash.local`), `users.is_bot` boolean flag, bots excluded from leaderboards and email flows via that flag.
- **Spawn safety:** `WorldService::spawnPlayer` already queries only `wasteland` tiles inside the spawn band (`app/Domain/World/WorldService.php:366-422`). No production fix needed — this plan adds a regression test only.

---

## Feature 1 — Full MDN (Phase 4)

### Spec anchor
`gameplay-ultraplan.md` §12 and `technical-ultraplan.md` §6.6. Quick recap: 50-member cap, same-MDN attacks/spies blocked, 24h join/leave cooldown before offensive actions, formal alliances declarative-only, shared journal with most-helpful-first voting.

### Config keys (extend `config/game.php` under `mdn`)
Existing: `max_members`, `join_leave_cooldown_hours`, `same_mdn_attacks_blocked`, `formal_alliances_prevent_attacks`. Add:
- `mdn.tag_max_length` (default 6)
- `mdn.name_max_length` (default 50)
- `mdn.creation_cost_cash` (default 10.00 — configurable; pulls from treasury so there's a gold sink)
- `mdn.journal.enabled` (default true)
- `mdn.journal.max_entries_per_mdn` (default 500)

Follow project rule: no hardcoded balance values. Everything goes through `GameConfig::get()`.

### Migrations (new, under `database/migrations/`)
Use `2026_04_11_NNNN_*` filenames, picking up after the current last one.

1. `create_mdns_table.php`
   - `id`, `name` (varchar 50, unique, case-insensitive like users), `tag` (varchar 6, unique, case-insensitive), `leader_player_id` (FK → players), `member_count` (unsigned small int, denormalized), `motto` (varchar 200 nullable), `timestamps`
2. `create_mdn_memberships_table.php`
   - `mdn_id`, `player_id` (FK both), `role` enum(`leader`,`officer`,`member`), `joined_at`, composite PK `(mdn_id, player_id)`, index `(player_id)` unique (a player belongs to ≤1 MDN)
3. `create_mdn_alliances_table.php`
   - `id`, `mdn_a_id`, `mdn_b_id`, `declared_at`, unique on sorted pair (lower id first, enforced in service)
4. `create_mdn_journal_entries_table.php`
   - `id`, `mdn_id`, `author_player_id`, `tile_id` (nullable — an entry can be general or pinned to a tile), `body` (text, length-capped), `helpful_count`, `unhelpful_count` (denormalized), `timestamps`
5. `create_mdn_journal_votes_table.php`
   - `id`, `entry_id`, `player_id`, `vote` enum(`helpful`,`unhelpful`), `timestamps`, unique `(entry_id, player_id)`

No changes to `players` (columns already present). Add a foreign key from `players.mdn_id` → `mdns.id` (on delete: set null) in a separate follow-up migration since the table now exists.

### Models (new, under `app/Models/`)
- `Mdn` — `hasMany` memberships, `hasMany` journal entries, `belongsToMany` alliances (self-join), `belongsTo` leader
- `MdnMembership` — pivot model with `role` cast
- `MdnAlliance`
- `MdnJournalEntry` — `hasMany` votes
- `MdnJournalVote`

Add to `Player`:
- `public function mdn(): BelongsTo` (uses existing `mdn_id`)
- `public function mdnMembership(): HasOne`

### Domain services (new, under `app/Domain/Mdn/`)

All services follow the existing pattern seen in `AttackService` / `SpyService`: constructor-injected `GameConfigResolver`, wrap writes in `DB::transaction`, `lockForUpdate` on rows that affect invariants, dispatch domain events after commit, throw typed exceptions.

1. **`MdnService`** — membership lifecycle
   - `create(int $leaderPlayerId, string $name, string $tag, ?string $motto): Mdn`
     - Validates name/tag uniqueness, length, creation cost deducted from leader
     - Creates mdn + leader membership in one transaction, sets `player.mdn_id` + `mdn_joined_at`
   - `join(int $playerId, int $mdnId): void` — enforces max_members, no-MDN-already, sets `mdn_joined_at`, updates `member_count`
   - `leave(int $playerId): void` — sets `mdn_left_at`, decrements count; if last leader leaves, disbands
   - `kick(int $leaderPlayerId, int $targetPlayerId): void` — role check
   - `promote(int $leaderPlayerId, int $targetPlayerId, string $role): void`
   - `disband(int $leaderPlayerId): void` — drops alliances, clears memberships, deletes MDN
   - `assertCanAttackOrSpy(Player $attacker, Player $target): void` — shared helper for combat services:
     - If `mdn.same_mdn_attacks_blocked` and same `mdn_id` (non-null) → `CannotAttackException::sameMdn()` / `CannotSpyException::sameMdn()`
     - If attacker's `mdn_joined_at` / `mdn_left_at` within `mdn.join_leave_cooldown_hours` → throw hop cooldown exception

2. **`MdnAllianceService`** — `declare(int $leaderPlayerId, int $otherMdnId)`, `revoke(int $leaderPlayerId, int $allianceId)`. Declarative only: the config flag `mdn.formal_alliances_prevent_attacks` is already `false`, so alliances appear in UI but don't block combat.

3. **`MdnJournalService`** — `addEntry(int $playerId, ?int $tileId, string $body)`, `vote(int $playerId, int $entryId, string $vote)`, `list(int $mdnId, string $sort = 'helpful'): Collection`. Sorting: `helpful_count DESC, created_at DESC` by default.

### Exceptions
Add `app/Domain/Exceptions/MdnException.php` with named constructors (`nameTaken`, `tagTaken`, `alreadyInMdn`, `notLeader`, `atCapacity`, `notAMember`, `selfAction`, etc.). Extend `CannotAttackException` + `CannotSpyException` with `sameMdn()` and `mdnHopCooldown(int $hoursRemaining)`.

### Combat integration (modify existing services)
- `app/Domain/Combat/AttackService.php:61` — inside the transaction, after defender lookup, call `$this->mdn->assertCanAttackOrSpy($attacker, $defender)`. Inject `MdnService` via constructor.
- `app/Domain/Combat/SpyService.php` — same hook before the roll.

### Events (use existing `app/Events/MdnEvent.php`)
Dispatch from services: `mdn.created`, `mdn.member_joined`, `mdn.member_left`, `mdn.member_kicked`, `mdn.promoted`, `mdn.disbanded`, `mdn.alliance_declared`, `mdn.journal_entry_added`. `RecordActivityLog::handleMdnEvent` is already wired — just fire the events.

### Routes + Controllers

Add to `routes/web.php` inside the existing authed/verified/claimed-username/block.broken_item group:
```
Route::prefix('mdn')->group(function () {
    Route::get('/', [MdnController::class, 'index'])->name('mdn.index');          // browser
    Route::get('/create', [MdnController::class, 'create'])->name('mdn.create');
    Route::post('/', [MdnController::class, 'store'])->name('mdn.store');
    Route::get('/{mdn}', [MdnController::class, 'show'])->name('mdn.show');        // members, journal, alliances
    Route::post('/{mdn}/join', [MdnController::class, 'join'])->name('mdn.join');
    Route::post('/{mdn}/leave', [MdnController::class, 'leave'])->name('mdn.leave');
    Route::post('/{mdn}/kick/{player}', [MdnController::class, 'kick'])->name('mdn.kick');
    Route::post('/{mdn}/promote/{player}', [MdnController::class, 'promote'])->name('mdn.promote');
    Route::post('/{mdn}/disband', [MdnController::class, 'disband'])->name('mdn.disband');

    Route::post('/{mdn}/alliances', [MdnAllianceController::class, 'store'])->name('mdn.alliances.store');
    Route::delete('/{mdn}/alliances/{alliance}', [MdnAllianceController::class, 'destroy'])->name('mdn.alliances.destroy');

    Route::post('/{mdn}/journal', [MdnJournalController::class, 'store'])->name('mdn.journal.store');
    Route::post('/{mdn}/journal/{entry}/vote', [MdnJournalController::class, 'vote'])->name('mdn.journal.vote');
});
```

Mirror the same set under `/api/v1/mdn/*` as `app/Http/Controllers/Api/V1/*` controllers. Both sides stay thin and call the same domain services. Exactly the pattern already used by `Web\MapController` and `Api\V1\MapController`.

New controller files:
- `app/Http/Controllers/Web/MdnController.php`
- `app/Http/Controllers/Web/MdnAllianceController.php`
- `app/Http/Controllers/Web/MdnJournalController.php`
- `app/Http/Controllers/Api/V1/MdnController.php`
- `app/Http/Controllers/Api/V1/MdnAllianceController.php`
- `app/Http/Controllers/Api/V1/MdnJournalController.php`

### UI (Inertia/Vue)

New pages under `resources/js/Pages/Game/Mdn/`:
- `Index.vue` — list of all MDNs, search by name/tag, join button, "Create MDN" CTA
- `Create.vue` — name/tag/motto form
- `Show.vue` — tabs: Members / Journal / Alliances. Shows member list w/ roles, leader-only actions (kick, promote, invite link), journal feed (sort by helpful), entry composer, alliance list with declare/revoke for leaders
- Add a nav link to the existing layout (same spot as Atlas / Activity Log)

Existing Map.vue "player info" panel should show the player's MDN tag next to their name when set. Attack/spy target info should also surface the defender's MDN tag and grey out the action if same-MDN.

### Filament admin
New `app/Filament/Admin/Resources/MdnResource.php` with:
- Table: name, tag, leader, member_count, created_at, alliances count
- Form: edit name, tag, motto, reassign leader, disband button
- Relation manager: members (kick/promote), alliances, journal entries
- Follows the existing `GameSettingResource` pattern

### Tests (Pest, under `tests/Feature/Mdn/`)
- `MdnCreateTest.php` — create happy path, duplicate name, duplicate tag, insufficient cash
- `MdnJoinLeaveTest.php` — join, capacity cap, already-in-mdn rejection, leave, last-leader disband
- `MdnRolesTest.php` — kick, promote, non-leader rejection
- `MdnCombatBlockingTest.php` — **critical**: attack + spy against same-MDN target throws; against other MDN passes
- `MdnHopCooldownTest.php` — attacks blocked within 24h of joining/leaving an MDN
- `MdnAllianceTest.php` — declare, revoke, alliances do NOT block combat (declarative-only contract)
- `MdnJournalTest.php` — add entry, vote, sort by helpful

### Files to modify in this feature
| File | Change |
|---|---|
| `database/migrations/2026_04_11_NNNN_*` | 5 new migration files + FK follow-up |
| `config/game.php` | Add `tag_max_length`, `name_max_length`, `creation_cost_cash`, `journal.*` under `mdn` |
| `app/Models/Player.php` | Add `mdn()`, `mdnMembership()` relationships |
| `app/Domain/Combat/AttackService.php` | Inject `MdnService`, call `assertCanAttackOrSpy` |
| `app/Domain/Combat/SpyService.php` | Same |
| `app/Domain/Exceptions/CannotAttackException.php` | `sameMdn()`, `mdnHopCooldown()` factories |
| `app/Domain/Exceptions/CannotSpyException.php` | Same |
| `routes/web.php`, `routes/api.php` | Route groups |
| `resources/js/Layouts/*` | Add MDN nav link |
| `resources/js/Pages/Game/Map.vue` | Show defender MDN tag, grey out same-MDN actions |

---

## Feature 2 — Bot Players

### Goals (locked)
- Bots are real `Player` rows that run through the identical domain services as humans.
- Primary objective: **maximize Akzar Cash**. Three difficulty tiers differentiate strategy, not rule-bending.
- Spawn, destroy, retune, list, and run via Artisan.
- Backgrounded via the Laravel 11 scheduler, no queue worker required.

### Schema changes
1. `add_is_bot_to_users_table.php` — `is_bot` boolean default false, index.
2. `add_bot_fields_to_players_table.php`
   - `bot_difficulty` enum(`easy`,`normal`,`hard`) nullable
   - `bot_last_tick_at` timestamp nullable
   - `bot_moves_budget` int default 0 — carries over across ticks so bots save up for big actions
   - All nullable on purpose: non-bot players have NULL in every bot column.

### Config keys (new section `bots` in `config/game.php`)
```
'bots' => [
    'tick_interval_minutes' => 5,
    'actions_per_tick_max' => 3,
    'difficulty' => [
        'easy' => [
            'label' => 'Easy',
            'drill_weight' => 70,    // percentages of action mix
            'shop_weight' => 20,
            'spy_weight' => 5,
            'attack_weight' => 5,
            'upgrade_threshold_cash' => 50.0,   // only buys stat upgrades above this
            'min_target_cash' => 20.0,          // won't waste moves raiding poor targets
            'risk_tolerance' => 0.3,            // 0..1; scales 'will I attack a stronger opponent?'
        ],
        'normal' => [
            'label' => 'Normal',
            'drill_weight' => 50,
            'shop_weight' => 20,
            'spy_weight' => 15,
            'attack_weight' => 15,
            'upgrade_threshold_cash' => 25.0,
            'min_target_cash' => 10.0,
            'risk_tolerance' => 0.55,
        ],
        'hard' => [
            'label' => 'Hard',
            'drill_weight' => 35,
            'shop_weight' => 15,
            'spy_weight' => 25,
            'attack_weight' => 25,
            'upgrade_threshold_cash' => 10.0,
            'min_target_cash' => 5.0,
            'risk_tolerance' => 0.8,
        ],
    ],
],
```

Follows the project rule: every bot balance lever lives in config, not in bot code.

### Domain layer (new, under `app/Domain/Bot/`)

**`BotDecisionService`**
- Constructor: `GameConfigResolver $config`, `RngService $rng`, plus every action service the bot might call (`TravelService`, `DrillService`, `ShopService`, `SpyService`, `AttackService`, `MdnService`).
- `tick(Player $bot): array` — runs up to `actions_per_tick_max` actions; returns a summary of what happened (for logging).
- Decision order each step (after reconciling moves via existing `MoveRegenService`):
  1. If `immunity_expires_at` is still active **and** bot has an adjacent oil field → drill (safe ramp).
  2. Roll a weighted action from `drill/shop/spy/attack` using the tier's weights via `RngService::rollWeighted` with eventKey `"bot.{playerId}.{tickCount}"` (deterministic, replayable).
  3. Execute the chosen action via the corresponding domain service. Catch known exceptions (`InsufficientMovesException`, `CannotDrillException`, etc.) and fall back to a safer action (drill → travel → no-op).
- **Action implementations** — all delegate to existing services:
  - **Drill**: Walk to nearest known oil field (via `FogOfWarService` discovered tiles), pick a grid cell, call `DrillService::drill()`.
  - **Shop**: If on a post, call `ShopService::purchase()` with an item chosen by tier — Easy favors consumables, Hard favors transports + stat upgrades when `akzar_cash > upgrade_threshold_cash`.
  - **Spy**: Travel to a discovered enemy base meeting `min_target_cash`, call `SpyService::spy()`.
  - **Attack**: Only if a valid spy exists on target within `spy_decay_hours`. Call `AttackService::attack()`. Hard tier chains a follow-up spy against a new target after a successful raid.
  - **Travel**: Reuse `TravelService::travel()`. Target selection by tier: Easy walks to nearest oil field; Hard walks toward high-cash discovered bases or unexplored frontier.
- **Same rules, no shortcuts**: bots call the exact services humans call. The combat services will (after Feature 1) also enforce same-MDN blocking and MDN hop cooldowns for bots automatically.
- **No MDN creation from bots in v1** — bots stay unaffiliated to avoid alliance-gaming exploits. Future tuning knob.

**`BotSpawnService`**
- `spawn(string $name, string $difficulty): Player`
  - Creates a `User` with `is_bot=true`, synthetic email `bot-{uuid}@bots.cashclash.local`, random password, `name_claimed_at = now()`.
  - Calls the existing `WorldService::spawnPlayer($user->id)` (same path humans take — random spawn, random location as required).
  - Sets `players.bot_difficulty` on the returned player.
- `destroy(Player $bot): void` — releases the base tile back to `wasteland`, deletes player, deletes user. Guarded by `is_bot` check.
- `setDifficulty(Player $bot, string $difficulty): void`

### Artisan commands (new, under `app/Console/Commands/`)

1. **`BotsSpawn`** — `php artisan bots:spawn {count} {--difficulty=normal} {--name=*}`
   - `count` = integer, how many bots
   - `--difficulty` = easy|normal|hard, validated against config
   - `--name` = repeatable option for explicit names; if fewer names than `count`, the rest auto-generate (`Bot-{adjective}-{noun}` pulled from a small word list in config)
   - Calls `BotSpawnService::spawn()` per bot, prints a table of (id, name, difficulty, spawn tile coords).
2. **`BotsDestroy`** — `php artisan bots:destroy {id?*} {--all} {--difficulty=}`
   - Destroys one or more specific IDs, or all bots, or all bots of a given difficulty. Confirmation prompt unless `--force`.
3. **`BotsSetDifficulty`** — `php artisan bots:set-difficulty {id} {difficulty}`
4. **`BotsList`** — `php artisan bots:list` — table of all bots with id, name, difficulty, cash, tile, last tick.
5. **`BotsTick`** — `php artisan bots:tick {--id=*} {--limit=50}`
   - Queries `users.is_bot = true` players ordered by `bot_last_tick_at ASC NULLS FIRST`.
   - Processes up to `--limit` per invocation (prevents one tick from blocking the scheduler too long).
   - For each bot: calls `BotDecisionService::tick()`, updates `bot_last_tick_at`, logs a one-line summary.
   - Safe to run manually too.

### Scheduler wiring
Extend `routes/console.php`:
```php
use Illuminate\Support\Facades\Schedule;
use App\Domain\Config\GameConfig;

$interval = GameConfig::get('bots.tick_interval_minutes', 5);
Schedule::command('bots:tick')
    ->cron("*/{$interval} * * * *")
    ->withoutOverlapping()
    ->runInBackground();
```
Without overlapping is critical — if a tick runs long, we don't want two running concurrently. Production (DirectAdmin + cron calling `schedule:run` every minute per project convention) will pick this up automatically.

### Bot exclusion points
- **Leaderboards** — any future leaderboard query filters `whereHas('user', fn($q) => $q->where('is_bot', false))`. Not critical in Phase II since leaderboards aren't built yet; document the constraint.
- **Email flows** — Fortify/Socialite/email-verification paths are only triggered by real registration; bots bypass because `BotSpawnService` creates the User row directly. No hook needed.
- **Broadcast channels** — bots have private channels but no one listens, harmless.
- **Admin flag** — the existing `User::is_admin` stays distinct from `is_bot`.

### Filament admin (small addition)
Extend the user list or add a `Bots` Filament resource showing: id, name, difficulty, cash, barrels, tile, last tick. Include quick actions: destroy, change difficulty. Reuses `BotSpawnService`.

### Tests (Pest, under `tests/Feature/Bots/`)
- `BotSpawnTest.php` — spawn creates user+player with `is_bot=true`, location is inside spawn band, difficulty is persisted
- `BotTickTest.php` — with a seeded world, one tick causes at least one valid action; no exceptions bubble up; `bot_last_tick_at` advances
- `BotDestroyTest.php` — destroy releases tile back to wasteland
- `BotDifficultyStrategyTest.php` — over 100 seeded ticks, Hard tier issues more attack actions than Easy (sanity check on weights)
- `BotImmunityTest.php` — a freshly-spawned bot does not get attacked by another bot due to `immunity_expires_at`

### Files created / modified in this feature
| File | Change |
|---|---|
| `database/migrations/*_add_is_bot_to_users_table.php` | New |
| `database/migrations/*_add_bot_fields_to_players_table.php` | New |
| `config/game.php` | Add `bots.*` section |
| `app/Models/User.php` | Cast `is_bot`, scope `whereBot()` |
| `app/Models/Player.php` | Cast `bot_difficulty`, `bot_last_tick_at` |
| `app/Domain/Bot/BotDecisionService.php` | New |
| `app/Domain/Bot/BotSpawnService.php` | New |
| `app/Console/Commands/BotsSpawn.php` | New |
| `app/Console/Commands/BotsDestroy.php` | New |
| `app/Console/Commands/BotsSetDifficulty.php` | New |
| `app/Console/Commands/BotsList.php` | New |
| `app/Console/Commands/BotsTick.php` | New |
| `routes/console.php` | Register scheduled `bots:tick` |
| `app/Filament/Admin/Resources/BotResource.php` | New (optional nice-to-have) |

---

## Feature 3 — Spawn Tile Safety Check

### Finding
`WorldService::spawnPlayer` at `app/Domain/World/WorldService.php:366-422` already:
1. Queries only `Tile::where('type', 'wasteland')` inside the spawn band.
2. Throws `RuntimeException` if none available.
3. Converts the chosen wasteland tile to `base`.

Non-playable tile types (`landmark`, `auction`) and already-occupied types (`oil_field`, `post`, `base`) are **not** selectable. No production bug.

### Action: lock it in with a regression test
New `tests/Feature/World/SpawnSafetyTest.php`:
- Seed a small world where every tile inside the spawn band is non-wasteland (`landmark`, `oil_field`, `post`, `base`). Assert `spawnPlayer` throws `RuntimeException`.
- Seed a world where exactly one wasteland tile exists inside the band, surrounded by non-playable tiles. Assert the spawn lands on that tile and the tile is converted to `base`.
- Spawn two users in a row. Assert neither lands on the other's base tile, neither lands on a non-wasteland type, both end up on tiles converted from `wasteland → base`.
- Spawn a bot via `BotSpawnService::spawn()`. Assert same invariants — bots and humans share the same code path.

No changes to `WorldService`. This test is the contract: any future regression (e.g., a well-meaning refactor that broadens the type filter) is caught immediately.

---

## Build order

1. **Bot schema + `is_bot` flag + spawn test.** Smallest, lowest-risk. Gives us a safety net for everything that follows. Includes the spawn-safety regression tests (Feature 3).
2. **MDN schema + models + core service (create/join/leave/kick/promote).** Domain foundation.
3. **MDN combat integration.** Modify `AttackService` + `SpyService` to call `MdnService::assertCanAttackOrSpy`. Write combat-blocking + hop-cooldown tests now while the behavior is fresh.
4. **MDN alliances + journal.** Optional slice — can be deferred a day if needed, but the plan ships them.
5. **MDN Web + API controllers + routes.** Dual-layer, both thin.
6. **MDN Vue pages + nav link + Map.vue tag surfacing.**
7. **MDN Filament admin resource.**
8. **Bot domain layer — `BotDecisionService`, `BotSpawnService`.** Now that MDN combat rules exist, bots automatically respect them.
9. **Bot Artisan commands — spawn, destroy, set-difficulty, list, tick.**
10. **Bot scheduler registration in `routes/console.php`.**
11. **Bot Filament resource (optional).**
12. **Full Pest test pass.** `php artisan test --parallel` on the VPS.

Each stage ends with the project's convention: staged but uncommitted — the user commits manually.

---

## Verification plan

This project has no local runtime (Windows dev, Debian VPS test). Per the dev workflow: push code, `git pull` on VPS, run migrations and tests there.

1. **Migrations** — `php artisan migrate` on VPS. Confirm `mdns`, `mdn_memberships`, `mdn_alliances`, `mdn_journal_entries`, `mdn_journal_votes` exist; `users.is_bot`, `players.bot_difficulty` added.
2. **Unit + feature tests** — `php artisan test --parallel`. All green, including the new `tests/Feature/Mdn/*` and `tests/Feature/Bots/*` suites and the spawn-safety regression.
3. **MDN smoke test (manual)** — Register two users, have one create an MDN, invite/join the other, verify same-MDN attack is blocked with the correct error message, verify a third user (no MDN) can still attack both.
4. **Journal smoke test** — post an entry, have another member vote helpful, verify sort order.
5. **Alliance smoke test** — declare alliance with another MDN, verify UI shows it and combat is still allowed (declarative-only contract).
6. **Bot smoke test**:
   - `php artisan bots:spawn 3 --difficulty=normal --name=Alpha --name=Beta --name=Gamma`
   - `php artisan bots:list` — confirm 3 rows, random tile locations, inside spawn band.
   - `php artisan bots:tick` — confirm each bot performs ≥1 action, no exceptions in the log.
   - Wait 5–10 min, confirm the scheduler fired another tick automatically (check `bot_last_tick_at` advanced).
   - `php artisan bots:set-difficulty <id> hard` — confirm difficulty changed.
   - `php artisan bots:destroy <id>` — confirm base tile returns to `wasteland`.
7. **Combat×Bot test** — have a real player attack a bot; bot is a valid target. Have two bots of opposing MDNs (if we later enable bot MDN joining) verify cross-MDN still works.
8. **Leaderboards / email** — spot-check that nothing leaks bot emails or shows bots in UI where unintended.

---

## Non-goals (explicitly deferred)

- **Bots in MDNs.** v1 bots stay solo. Adding them to MDNs invites farming exploits; revisit after human MDN play is observed.
- **Bot machine learning / heuristics tuning.** Weights are config-keyed; tune by editing config, not code.
- **MDN shared map tier-gated contributions, hit contracts, MDN warfare bonus cash.** Spec §12.1 mentions these but they depend on the hit contract and map-sharing systems that don't exist yet. Phase III material.
- **Leaderboards.** Not built. When they are, filter on `users.is_bot = false`.
- **Bot chat / journal entries.** Bots don't write journal entries in v1.

---

## Open config decisions (not blockers)

- Exact creation cost for MDNs (`mdn.creation_cost_cash`) — plan defaults to A10.00, tune after playtesting.
- Exact bot tick cadence (`bots.tick_interval_minutes`) — plan defaults to 5 minutes; raise on production if the VPS feels loaded.
- Bot name word list — a small `config/bot_names.php` or inline in `config/game.php` under `bots.name_pool`. Either works; implementer picks whichever feels cleaner.

---

## Post-audit fixes (applied after the initial implementation)

A three-agent audit — backend code review, frontend/UX review, and PM completeness check — surfaced the following issues, all of which have been fixed in the same patch:

1. **`MdnService::removeMember` used `$membership->delete()` on a composite-key model with `$primaryKey = null`** — Eloquent's instance delete would have silently failed. Switched to a query-builder delete keyed on `(mdn_id, player_id)`.
2. **Mobile responsive nav was missing the MDN link** — `AuthenticatedLayout.vue` only added MDN to the desktop `NavLink` row, not the hamburger menu. Added a matching `ResponsiveNavLink`.
3. **`MdnController` bypassed `GameConfigResolver`** — the Web controller used the raw `config()` helper for config reads and hardcoded validator lengths. Injected `GameConfigResolver` and drove all validator maxes and Inertia props through it so live admin overrides take effect.
4. **`BotSpawnService::generateName` used `array_rand()` + `random_int()`** — project rule violation. Re-routed through `RngService::rollInt` so bot name generation is auditable/replayable.
5. **`MdnService::create` did not dispatch `MdnEvent`** — the activity-log hook for `mdn.created` was never firing. Added a post-commit dispatch mirroring the other lifecycle methods.
6. **`mdns.tag` column width / config / validator disagreement** — migration stored `varchar(8)` while the config capped at 6 and Web/API validators allowed 8. Raised `mdn.tag_max_length` default to 8 so all three agree.
7. **Accessibility gaps** — `Create.vue` form inputs now have `for`/`id` label pairs; `Show.vue` tab widget now has `role="tablist"`, `role="tab"`, `aria-selected`, `role="tabpanel"`, `aria-labelledby`, and `aria-controls`; `Index.vue` search input has `aria-label`; `Show.vue` journal composer has a visually-hidden label.
8. **Missing tests called out in the plan** — added `tests/Feature/Mdn/MdnRolesTest.php` (promote/kick/non-leader rejection + leadership transfer on leader-leave) and `tests/Feature/Bots/BotDestroyTest.php` (tile-returns-to-wasteland and reuse invariant).
9. **Missing Filament relation managers** — `MdnResource` now has `MembershipsRelationManager` (view/kick members, role edit) and `JournalEntriesRelationManager` (moderate entries).
10. **Missing `BotResource`** — plan flagged this as optional but the user explicitly asked for admin to destroy/retune bots without SSH. Added a full Filament resource with list/edit, a "Destroy" action that calls `BotSpawnService::destroy`, and a difficulty filter.
11. **`MdnJournalService` threw `MdnException::nameInvalid` for body errors** — produced misleading error messages. Added `MdnException::bodyInvalid()` and wired journal validation through it.
12. **`Show.vue` rendered orphaned alliances as empty brackets** — added an explicit `[Disbanded MDN]` fallback when `other_mdn` is null (e.g., an ally got disbanded mid-session).

False positives from the audit (noted so they are not re-flagged):
- `BotDecisionService::rollAction` reading `$tierCfg['action_weights']` — the config actually does nest `action_weights` under each difficulty tier, so this works as written.
- `BotsTick` order-by using `bot_last_tick_at IS NULL DESC` — valid on both MySQL and SQLite (`IS NULL` evaluates to 1/0 and `DESC` puts NULLs first, which is the intended starvation floor).
- `assertCanAttackOrSpy` pre-seeded `mdn_joined_at` cooldown — `WorldService::spawnPlayer` does not pre-seed that column, so the theoretical issue does not manifest; the null-guard in the service is correct.
