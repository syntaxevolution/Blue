# Loot Crates — Feature Plan (locked 2026-04-14)

Adds two related systems: **real loot crates** (randomly appearing on wasteland tiles) and **sabotage loot crates** (player-placed traps disguised as real crates). Both share the same tile slot, the same frontend modal, and the same underlying DB row.

---

## Overview

### Real loot crates
- Only appear on **wasteland** tiles.
- On arrival, if no crate exists on the tile, roll `loot.real_crate.spawn_chance` (default **1%**). If hit, persist a new real crate on that tile.
- **Persistent**: if the arriving player declines to open it, the crate stays for the next visitor.
- Contents (rolled on open, all configurable):
  - Nothing — 25%
  - Oil — 10% (100–10,000 barrels, lower values weighted heavier)
  - Cash — 5% ($1.00–$10.00, lower values weighted heavier)
  - Random store item — 60% (cheaper items weighted heavier, inverse-price)
    - If the rolled item is non-stackable and the player already owns it, outcome becomes "out of luck" (nothing).

### Sabotage loot crates
- Purchased from the **Stealth post** as toolbox items. Two variants:
  - `crate_siphon_oil` — 500 barrels — siphons 5–20% of victim's oil
  - `crate_siphon_cash` — 10,000 barrels — siphons 5–20% of victim's cash
- Placed by navigating to a wasteland tile and deploying from the toolbox. One crate slot per tile (real or sabotage).
- Persist until opened. Placer can see their own but **cannot open** them.
- On trigger: siphoned currency goes **instantly** to the placer. Pusher notifies the placer, Hostility Log entry is written for both placer and victim. Victim sees placer's name only if they own the Hostility Log item.
- **48h new-player immunity**: immune victims who open a sabotage crate consume it with **no effect** and **no credit** to the placer, but the placer is notified via Hostility Log: *"Immune player {name} triggered your sabotage crate — no effect."* Immune players **can** place sabotage crates.
- Sabotage is a **separate channel** from raids — does not count against the 20% loot ceiling or the 12h raid cooldown.
- **Deployment cap** per player: `max(5, floor(world_tile_count / 2000) * 5)`. Unlimited toolbox storage; cap only applies to crates currently on the map.

### Shared
- **No visual difference** between real and sabotage crates — that's the whole point of the sabotage variant.
- **One slot per tile**, regardless of type. A tile with a sabotage crate will not roll for a real crate.
- **Opening costs 0 moves** (tunable).
- **Never forced** — players can always decline/skip.
- Crates **never appear on the atlas map**.
- Bots open crates with configurable probability per difficulty (default **75%** across the board).

---

## Database

### New table: `tile_loot_crates`

```
id                      bigint PK
tile_x                  int
tile_y                  int
placed_by_player_id     bigint nullable  -- null = real crate, set = sabotage
device_key              string nullable  -- null = real, 'siphon_oil' | 'siphon_cash'
placed_at               timestamp
opened_at               timestamp nullable
opened_by_player_id     bigint nullable
outcome                 json nullable    -- reward breakdown / siphon amounts
created_at, updated_at

INDEX (tile_x, tile_y)
INDEX (placed_by_player_id)
UNIQUE partial INDEX (tile_x, tile_y) WHERE opened_at IS NULL
```

The unique partial index enforces "one open crate per tile". MySQL 8 doesn't support partial indexes directly, so enforce via `(tile_x, tile_y, opened_at)` unique on non-null `opened_at` — or better, drop the unique and enforce via `lockForUpdate` + count check in the service (simpler, matches existing sabotage pattern).

### Items catalog additions

Two new rows in `items_catalog`:

| key | post_type | name | price_barrels | effects |
|---|---|---|---|---|
| `crate_siphon_oil` | stealth | Oil Siphon Crate | 500 | `{"deployable_loot_crate": {"kind": "oil"}}` |
| `crate_siphon_cash` | stealth | Cash Siphon Crate | 10000 | `{"deployable_loot_crate": {"kind": "cash"}}` |

Both stackable (no `max_stacks` limit on purchase — the deployed-cap is enforced at placement time).

---

## Config keys (`config/game.php` + `game_settings` override)

```php
'loot' => [
    'real_crate' => [
        'spawn_chance' => 0.01,
        'outcomes' => [
            'nothing' => 25,
            'oil'     => 10,
            'cash'    => 5,
            'item'    => 60,
        ],
        'oil'  => ['min' => 100,  'max' => 10000, 'weight_exponent' => 2.5],
        'cash' => ['min' => 1.00, 'max' => 10.00, 'weight_exponent' => 2.5],
        'item' => [
            'weighting' => 'inverse_price', // inverse_price | uniform
            'exclude_keys' => [],            // none by default
        ],
    ],
    'sabotage' => [
        'steal_pct' => ['min' => 0.05, 'max' => 0.20],
        'max_deployed_base' => 5,
        'tiles_per_cap_step' => 2000,
        'immune_victim_behavior' => 'consume_no_credit', // crate is consumed, placer notified, no transfer
    ],
    'open_move_cost' => 0,
    'bots' => [
        'open_chance' => [
            'easy'    => 0.75,
            'medium'  => 0.75,
            'hard'    => 0.75,
            'brutal'  => 0.75,
        ],
    ],
],
```

All values accessible via `GameConfig::get('loot.real_crate.spawn_chance')` etc.

---

## Domain services

### `app/Domain/Loot/LootCrateService.php`

Public methods:

- `rollOrFetchForTile(Player $player, Tile $tile): ?TileLootCrate`
  Called from the movement hook. If tile is not wasteland → null. If an unopened crate exists → return it. Otherwise roll spawn_chance via RngService; if hit, insert a new real crate row and return it. Transactional, locks the tile row.

- `place(Player $player, Tile $tile, string $itemKey): TileLootCrate`
  Called from the "deploy from toolbox" API. Guards:
  - Player is on the tile
  - Tile is wasteland
  - Player owns ≥1 of the item
  - Item has `effects.deployable_loot_crate` set
  - No existing unopened crate on tile
  - Player's currently-deployed-and-unopened count < `deploymentCap()`
  - Placement is always allowed during new-player immunity
  Consumes 1 from `player_items`, inserts the crate row.

- `open(Player $player, TileLootCrate $crate): OpenResult`
  Transactional, locks crate row (`opened_at IS NULL`), locks player row, (if sabotage) locks placer + computes victim balance. Branches:
  - **Real crate** → rolls outcome via weighted table, applies reward to player.
  - **Sabotage, immune victim** → marks consumed, writes Hostility Log entry ("immune, no effect"), returns `immune_lucky` outcome.
  - **Sabotage, non-immune victim** → rolls steal_pct, computes siphon amount (min of requested pct × victim balance, capped at victim balance), transfers to placer, writes Hostility Log entries for both, fires Pusher event.
  - **Sabotage, placer trying to open own crate** → rejected.
  Marks `opened_at`, `opened_by_player_id`, `outcome`. Idempotent: if already opened, throws.

- `decline(Player $player, TileLootCrate $crate): void`
  No-op for both types (real crate stays, sabotage crate stays). Exists for API symmetry and in case we want to track decline stats later. Returns 200 without DB write.

- `deploymentCap(): int`
  `max(base, floor(tile_count / tiles_per_cap_step) * base)`. Tile count cached (5-minute TTL) via `cache()->remember`.

- `currentlyDeployedCount(Player $player): int`
  `tile_loot_crates` where `placed_by_player_id = player.id` AND `opened_at IS NULL`.

### Helper services

- `app/Domain/Loot/LootWeightingService.php` — pure functions for:
  - `weightedPick(array $weights): string` — for outcome table
  - `weightedRange(float $min, float $max, float $exponent, RngService $rng): float` — lower-heavy curve via `min + (max-min) * rand^exponent` (rand is uniform [0,1])
  - `inversePriceItemPick(Collection $items, RngService $rng): ?Item` — weights = `1 / max(price_barrels + price_cash*100, 1)` with excluded keys filtered out

### RNG

All rolls go through `RngService`. Named roll contexts so audits are traceable:
- `loot.spawn.{player_id}.{tile_key}`
- `loot.outcome.{crate_id}`
- `loot.oil_amount.{crate_id}`
- `loot.cash_amount.{crate_id}`
- `loot.item_pick.{crate_id}`
- `loot.sabotage_pct.{crate_id}`

---

## Movement hook

In `TransportMovementService::travel()` (or wherever the final post-move state is committed), after the player is committed to the new tile, call:

```php
$crate = $lootCrateService->rollOrFetchForTile($player, $destinationTile);
```

Attach to the response via `MapStateResource`:

```php
'loot_event' => $crate ? [
    'crate_id' => $crate->id,
    'token' => $this->signer->sign($crate->id, $player->id, ttl: 300),
] : null,
```

The **token** is a short-lived HMAC tying `(crate_id, player_id, timestamp)` so that opening the crate can't be replayed after navigating away, and so that decline events are idempotent.

---

## API endpoints

### Web (Inertia) + API (REST) — shared controllers call the same service

- `POST /api/v1/loot-crates/{crate}/open` — body: `{ token }` — returns `OpenResult` (outcome type, amounts/items, new balances)
- `POST /api/v1/loot-crates/{crate}/decline` — body: `{ token }` — returns 200
- `POST /api/v1/loot-crates/deploy` — body: `{ item_key }` — uses player's current tile, returns new crate id
- `GET  /api/v1/loot-crates/deployed` — list the placer's active deployed crates (for a toolbox management UI)

Web routes mirror these under `app/Http/Controllers/Web/LootCrateController.php`, API under `app/Http/Controllers/Api/V1/LootCrateController.php`, both thin, both call `LootCrateService`.

---

## Frontend

### `resources/js/Components/LootCrateModal.vue`

Props: `crate` (id, token), `state` ('discovered' | 'resolved'), `outcome` (when resolved).

States:
1. **Discovered**: "You found a loot crate." — [Open] [Leave it]
2. **Resolving**: spinner while the POST is in flight
3. **Resolved-real**: Shows outcome (nothing / oil amount / cash amount / item name+icon) — [Continue]
4. **Resolved-sabotage-victim**: Shows "This was a trap! {X barrels / $Y} was siphoned." — [Continue]
5. **Resolved-sabotage-immune**: "This was a trap — but your new-player immunity protected you." — [Continue]
6. **Resolved-placer-warning**: Shown when placer navigates onto their own deployed crate — "Your deployed trap." [Close]

Mobile-friendly via existing `Modal.vue` wrapper (`<dialog>` element). No layout changes needed.

### `Pages/Game/Map.vue` integration

- Render `<LootCrateModal>` when `mapState.loot_event` is present.
- Dismissing (close or decline or after-resolved continue) clears the `loot_event` prop locally.
- Deploy-from-toolbox: new action button on the wasteland tile detail panel, visible only if player owns ≥1 deployable crate item AND is under the deployment cap. Clicking shows a confirmation, then POSTs to `deploy`.

### `Pages/Game/Toolbox.vue` (or equivalent)

Show deployed-crate count badge + link to "My deployed crates" (list + tile coords), for placer awareness.

---

## Bot integration

In `BotGoalExecutor`, after executing a move step, check if a `loot_event` was attached to the move result (via `LootCrateService::rollOrFetchForTile` — called from the same service path as humans). If so:
- Roll against `loot.bots.open_chance.{difficulty}` via RngService.
- If hit, call `LootCrateService::open()`. If it's the bot's own deployed sabotage crate, skip silently.
- If miss, call `decline()` (no-op).

Bots never **place** sabotage crates in v1 (out of scope — keeps bot behavior predictable).

---

## Events & logging

- `LootCrateOpened` — fired on any open, carries player_id, crate_id, outcome summary. Low-priority activity log entry for player.
- `SabotageCrateTriggered` — fired on sabotage open, broadcast to placer via Pusher (private channel `App.Models.Player.{placer_id}`).
- Hostility Log entries (integrating with existing `AttackLog` model at `/attack-log`):
  - On sabotage trigger (non-immune): entry on both sides with amounts.
  - On sabotage trigger (immune): entry on placer side only, `no_effect` flag.
  - On real-crate open: no entry (it's not hostile).

---

## Tests (Pest)

### Unit
- `LootWeightingService::weightedPick` respects probability table within tolerance over N iterations.
- `weightedRange` produces lower-heavy distribution (mean < midpoint).
- `inversePriceItemPick` never picks excluded keys; cheaper items over-represented.
- `deploymentCap` math: 1000 tiles → 5; 2000 tiles → 5; 2001 tiles → 5; 4000 tiles → 10; 10000 tiles → 25.

### Feature
- **Real crate spawn**: force RNG to hit, assert crate row created, assert `loot_event` in response.
- **Real crate decline persists**: decline, re-enter tile with second player, crate still there.
- **Real crate open — each outcome type**: nothing / oil / cash / item, balance updated.
- **Real crate open — owned non-stackable item** falls back to nothing.
- **Sabotage place**: success path, failures for (non-wasteland tile, tile already has crate, at deployment cap, don't own item, not on tile).
- **Sabotage open by non-immune victim**: oil/cash siphoned, placer credited, Hostility Log entries on both sides, Pusher event dispatched.
- **Sabotage open by immune victim**: crate consumed, placer gets no credit, Hostility Log entry on placer side with `no_effect`, no event to victim.
- **Sabotage open attempt by placer**: rejected, crate untouched.
- **Concurrent open race**: two processes open same crate, one wins, one gets "already opened" error.
- **Cap enforcement**: deploy N crates up to cap, next deploy rejected.
- **Token validation**: open with invalid/expired token rejected.
- **Bot path**: seeded RNG, bot on wasteland with crate present, bot opens at configured rate.

### Regression
- Existing movement/drill tests still pass.
- `php artisan test --compact` clean.

---

## Build order

1. **Migration** — `tile_loot_crates` table + items_catalog seed.
2. **Model** — `TileLootCrate` with relationships (tile, placedBy, openedBy).
3. **Config** — `loot` section in `config/game.php`, add to GameSetting seed.
4. **Services** — `LootWeightingService`, `LootCrateService`.
5. **RNG contexts** — new named roll contexts.
6. **Movement hook** — integrate `rollOrFetchForTile` into travel flow.
7. **Response shape** — extend `MapStateResource` with `loot_event`.
8. **Token signer** — short-lived HMAC for open/decline.
9. **API controllers** — `LootCrateController` (Web + API v1), route bindings.
10. **Frontend** — `LootCrateModal.vue`, `Map.vue` integration, deploy action on tile panel, toolbox badge.
11. **Hostility Log integration** — write entries via existing `AttackLog` service.
12. **Pusher event** — `SabotageCrateTriggered` broadcast.
13. **Bot integration** — `BotGoalExecutor` hook.
14. **Filament admin** — expose new config keys in the GameSetting editor.
15. **Tests** — Pest unit + feature suite.
16. **Pint** — `vendor/bin/pint --dirty --format agent`.
17. **Full test suite** — `php artisan test --compact`.

---

## Open items to resolve inline
- Exact name/signature of `AttackLog` (Hostility Log) service for integration.
- Whether `MapStateResource` is the right attach point or if we need a new response field upstream.
- Filament GameSetting editor layout — fit new keys under an existing group or add a "Loot" tab.
