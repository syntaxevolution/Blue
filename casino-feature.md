# Roughneck's Saloon — Casino Feature Implementation Plan

## Context

The game needs a Casino tile type ("Roughneck's Saloon") with 4 games: Slots (solo), Roulette (group, timed rounds), Blackjack (group, works solo too), and Texas Hold'em (players only, no house). All casinos are interlinked — the tile is just an entry point, players at different casino tiles see the same tables. Entry fee is 50 barrels per visit (configurable). Each game has Cash and Oil table variants. All odds are realistic defaults but configurable from admin panel. House has infinite funds (no bankroll tracking).

---

## 1. Database Migrations

### 1A. Add `casino` to tiles enum
```sql
ALTER TABLE tiles MODIFY COLUMN type ENUM('base','oil_field','post','wasteland','landmark','auction','ruin','casino') NOT NULL
```
MariaDB-safe raw statement (Laravel schema builder can't add enum values).

### 1B. `casinos` companion table
Follows `posts`/`oil_fields` pattern — one-to-one with tiles.
```
casinos: id, tile_id (FK unique), name (varchar 128), timestamps
```
Minimal — no per-casino state since all are interlinked.

### 1C. `casino_sessions` — entry fee tracking
Prevents double-charge on page refresh. Short-lived.
```
casino_sessions: id, player_id (FK), entered_at, expires_at, fee_amount (decimal 12,2), created_at, updated_at
INDEX: (player_id, expires_at)
```

### 1D. `casino_tables` — game tables
```
casino_tables: id, game_type (enum: roulette, blackjack, holdem), currency (enum: akzar_cash, oil_barrels),
  label (varchar 64), min_bet (decimal 12,2), max_bet (decimal 12,2), seats (tinyint default 6),
  status (enum: waiting, active, paused, closed), state_json (json), round_number (uint default 0),
  round_started_at (timestamp null), round_expires_at (timestamp null), timestamps
INDEX: (game_type, currency, status)
```
`state_json` holds the full game state machine snapshot (deck, hands, pot, current turn). Read/written atomically inside locked transactions. Slots don't use this table — they're stateless per-spin.

### 1E. `casino_table_players` — seats
```
casino_table_players: id, casino_table_id (FK), player_id (FK), seat_number (tinyint),
  stack (decimal 12,2 default 0), status (enum: active, folded, sitting_out, left),
  joined_at (timestamp), last_action_at (timestamp null), timestamps
UNIQUE: (casino_table_id, seat_number)
UNIQUE: (casino_table_id, player_id)
INDEX: (player_id)
```
`stack` is for Hold'em buy-in balance only.

### 1F. `casino_rounds` — completed round audit log
```
casino_rounds: id, casino_table_id (FK null for slots), game_type (enum: slots, roulette, blackjack, holdem),
  currency (enum: akzar_cash, oil_barrels), round_number (uint), state_snapshot (json),
  rng_seed (varchar 255), result_summary (json), resolved_at (timestamp), created_at, updated_at
INDEX: (casino_table_id, round_number)
```

### 1G. `casino_bets` — individual bet records
```
casino_bets: id, casino_round_id (FK), player_id (FK), bet_type (varchar 32),
  amount (decimal 12,2), payout (decimal 12,2 default 0),
  net (decimal 12,2) AS (payout - amount) STORED, created_at, updated_at
INDEX: (player_id, created_at), INDEX: (casino_round_id)
```
`net` is a MariaDB generated column for quick profit/loss queries.

### 1H. `casino_chat_messages`
```
casino_chat_messages: id, casino_table_id (FK), player_id (FK), message (varchar 500), created_at
INDEX: (casino_table_id, created_at)
```
Immutable — no `updated_at`.

---

## 2. Config Structure

Add to `config/game.php`:

**Density** — in `world.density` (matches existing pattern):
```php
'casinos_per_tile' => 0.004,  // 4 per 1000 tiles
```

**Casino section** — new top-level `casino` key:
```php
'casino' => [
    'enabled' => true,
    'entry_fee_barrels' => 50,
    'session_duration_minutes' => 120,
    'names' => ["Roughneck's Saloon", "The Lucky Derrick", "Gusher's Den", "The Pipeline Lounge", "Barrel & Bone Casino"],

    'slots' => [
        'enabled' => true,
        'house_edge_pct' => 0.05,
        'min_bet_cash' => 0.10, 'max_bet_cash' => 500.00,
        'min_bet_barrels' => 10, 'max_bet_barrels' => 50000,
        'reel_count' => 3,
        'symbols' => [ /* weighted symbol pool */ ],
        'pay_table' => [ /* [symbol, count, multiplier] */ ],
    ],

    'roulette' => [
        'enabled' => true,
        'betting_window_seconds' => 60,
        'min_bet_cash' => 0.10, 'max_bet_cash' => 500.00,
        'min_bet_barrels' => 10, 'max_bet_barrels' => 50000,
        'max_bets_per_round' => 20,
        'payouts' => [ 'straight' => 35, 'split' => 17, 'street' => 11, 'corner' => 8, 'line' => 5, 'column' => 2, 'dozen' => 2, 'even_money' => 1 ],
        'tables_per_currency' => 1,
    ],

    'blackjack' => [
        'enabled' => true,
        'min_bet_cash' => 0.10, 'max_bet_cash' => 500.00,
        'min_bet_barrels' => 10, 'max_bet_barrels' => 50000,
        'max_seats' => 5, 'deck_count' => 6, 'reshuffle_penetration_pct' => 0.75,
        'dealer_hits_soft_17' => false, 'blackjack_payout_ratio' => 1.5,
        'insurance_enabled' => true, 'surrender_enabled' => true,
        'double_after_split' => true, 'max_splits' => 3,
        'turn_timer_seconds' => 30, 'tables_per_currency' => 1,
    ],

    'holdem' => [
        'enabled' => true,
        'min_players' => 2, 'max_seats' => 6, 'turn_timer_seconds' => 30,
        'min_buy_in_multiplier' => 20, 'max_buy_in_multiplier' => 100,
        'rake_pct' => 0.05, 'rake_cap_cash' => 5.00, 'rake_cap_barrels' => 500,
        'blinds' => [
            'cash' => [['small' => 0.05, 'big' => 0.10], ['small' => 0.25, 'big' => 0.50], ['small' => 1.00, 'big' => 2.00]],
            'barrels' => [['small' => 5, 'big' => 10], ['small' => 25, 'big' => 50], ['small' => 100, 'big' => 200]],
        ],
        'tables_per_blind_level' => 1,
    ],

    'chat' => ['enabled' => true, 'max_message_length' => 200, 'rate_limit_per_minute' => 10, 'history_load_count' => 50],
]
```

---

## 3. Domain Services

All under `app/Domain/Casino/`.

### CasinoService — entry point
- `enterCasino(int $playerId): array` — validate on casino tile, check existing session, deduct entry fee (lockForUpdate pattern from ShopService), create session row
- `hasActiveSession(int $playerId): bool`
- `getSession(int $playerId): ?CasinoSession`

### SlotMachineService — solo, stateless per-spin
- `spin(int $playerId, string $currency, float $bet): array` — validate session + bet limits, lock player, deduct bet, roll 3 reels via `RngService::rollWeighted`, calculate payout from config pay table, credit winnings, record round+bet, return `{reels, payout, balance}`
- RNG: `rollWeighted('casino.slots.reel', "{$playerId}:{$spinCounter}:{$reelIndex}", $symbolWeights)` per reel
- House edge achieved by tuning symbol weights so EV < 1.0

### RouletteService — timed group game
- `findOrCreateTable(string $currency): CasinoTable`
- `placeBet(int $playerId, int $tableId, string $betType, float $amount): array` — validate betting window open, validate bet type whitelist, lock player, deduct currency, store bet in state_json
- `resolveSpin(int $tableId): array` — called by delayed job. `RngService::rollInt('casino.roulette.spin', "{$tableId}:{$roundNumber}", 0, 36)`, calculate payouts per European odds, credit winners, record round, broadcast result, reset for next round
- State machine: `waiting → betting → spinning → resolved → betting`
- European single-zero (37 numbers, 2.7% house edge)
- Bet types: straight (35:1), split (17:1), street (11:1), corner (8:1), line (5:1), column/dozen (2:1), red/black/odd/even/high/low (1:1)

### BlackjackService — multi-player vs dealer (works solo)
- `joinTable`, `placeBet`, `dealHand`, `playerAction` (hit/stand/double/split/surrender), `resolveDealerHand`, `leaveTable`
- 6-deck shoe, reshuffle at configurable penetration
- Dealer stands on 17 (configurable hit soft 17)
- Blackjack pays 3:2 (configurable)
- State machine: `betting → dealing → player_turns (sequential L→R) → dealer_turn → payout → betting`
- Deck stored as array in state_json, dealt via RngService Fisher-Yates draws

### HoldemService — full Texas Hold'em
- `createTable`, `joinTable` (buy-in: wallet → stack), `leaveTable` (cash out: stack → wallet)
- `startHand`, `playerAction` (fold/check/call/raise/all-in), `advancePhase`, `showdown`, `handleTimeout`
- State machine: `waiting_for_players → pre_flop → flop → turn → river → showdown → pre_flop`
- Blinds posted per hand, configurable levels
- Rake: configurable % of pot, capped
- Side pots for all-in scenarios
- Turn timer: configurable seconds, auto-fold on expiry via delayed job

### HandEvaluator — pure poker hand ranking
- Standalone class, no deps. Evaluates 5-7 card hands.
- Returns comparable integer ranking for all standard hands (high card → royal flush)
- Build and unit test FIRST before HoldemService

### CasinoChatService
- `sendMessage(int $playerId, int $tableId, string $message): CasinoChatMessage`
- `recentMessages(int $tableId, int $limit): Collection`
- Rate limited per config

### CasinoTableManager — table lifecycle
- `ensureTablesExist(): void` — creates default tables from config if missing
- `cleanupEmptyTables(): void` — removes stale empty tables

### CasinoException — domain exceptions
Static factories: `notOnCasinoTile()`, `noActiveSession()`, `insufficientBalance()`, `invalidBetAmount()`, `invalidBetType()`, `tableIsFull()`, `notYourTurn()`, `bettingWindowClosed()`, `invalidAction()`, `minimumPlayersRequired()`

---

## 4. Real-Time Architecture

### WebSocket Channels
- `casino.table.{tableId}` — **presence channel** for all players at a table. Game state updates, chat, player join/leave.
- Existing `App.Models.User.{userId}` — **private channel** for Hold'em hole cards (MUST NOT go to table channel)

### Broadcast Events (in `app/Events/Casino/`)
| Event | Channel | Purpose |
|---|---|---|
| BettingWindowOpened | table.{id} | Roulette round start, timer |
| BetPlaced | table.{id} | Player placed bet (roulette) |
| RouletteResult | table.{id} | Spin outcome, payouts |
| BlackjackHandDealt | table.{id} | Cards dealt, dealer up card |
| BlackjackPlayerAction | table.{id} | Hit/stand/etc result |
| BlackjackDealerTurn | table.{id} | Dealer reveals and draws |
| BlackjackPayout | table.{id} | Round results |
| HoldemHoleCards | User.{id} (private!) | Player's own cards only |
| HoldemCommunityCards | table.{id} | Flop/turn/river |
| HoldemPlayerAction | table.{id} | Fold/call/raise notification |
| HoldemShowdown | table.{id} | Hand reveal, pot distribution |
| HoldemTurnTimer | table.{id} | Countdown updates |
| TableChatMessage | table.{id} | Chat message |
| PlayerJoinedTable | table.{id} | Seat taken |
| PlayerLeftTable | table.{id} | Seat vacated |

### Timer Mechanism
Redis-backed delayed job dispatch via Laravel Horizon:
- `ResolveRouletteRound` — dispatched with `->delay($windowSeconds)` when betting opens
- `HoldemTurnTimeout` — dispatched with `->delay($turnSeconds)` when player's turn starts
- Both guard against stale execution by checking round_number still matches

---

## 5. WorldService Integration

### rollTileSpec() — add casino to density cascade
After the landmark cutoff, before the wasteland fallback:
```php
$densityCasinos = (float) $this->config->get('world.density.casinos_per_tile');
$casinoCutoff = $landmarkCutoff + $densityCasinos;

if ($roll < $casinoCutoff) {
    return ['x' => $x, 'y' => $y, 'type' => 'casino', 'subtype' => null, 'seed' => $tileSeed];
}
```
File: `app/Domain/World/WorldService.php:209-266`

### persistTilePlan() — add step 7 for casinos
After the posts insertion block (step 6, line 407-425), add:
```php
// 7. Bulk-insert casinos for every planned casino tile.
$casinoRows = [];
foreach ($plan as $spec) {
    if ($spec['type'] !== 'casino') continue;
    $casinoRows[] = [
        'tile_id' => $tileIdByXy["{$spec['x']}:{$spec['y']}"],
        'name' => $this->pickCasinoName($spec['x'], $spec['y'], $seed),
        'created_at' => $now, 'updated_at' => $now,
    ];
}
foreach (array_chunk($casinoRows, 500) as $chunk) {
    Casino::insert($chunk);
}
$stats['casinos'] = count($casinoRows);
```

### Add CASINO_NAMES constant + pickCasinoName() method
Same pattern as POST_NAMES / pickPostName().

### Update getWorldInfo() to include casino density
### Update stats return type to include `casinos` count

---

## 6. MapStateBuilder Integration

Add to `tileDetail()` match (line 241):
```php
'casino' => $this->casinoDetail($tile, $player),
```

New method:
```php
private function casinoDetail(Tile $tile, Player $player): array
{
    $casino = Casino::where('tile_id', $tile->id)->first();
    $session = CasinoSession::where('player_id', $player->id)
        ->where('expires_at', '>', now())->first();

    return [
        'kind' => 'casino',
        'name' => $casino?->name ?? "Roughneck's Saloon",
        'has_active_session' => $session !== null,
        'session_expires_at' => $session?->expires_at?->toIso8601String(),
        'entry_fee_barrels' => (int) $this->config->get('casino.entry_fee_barrels'),
    ];
}
```

---

## 7. Retrofit Artisan Command

`app/Console/Commands/CasinoRetrofit.php` — signature: `casino:retrofit {--dry-run}`

Algorithm:
1. `$totalTiles = Tile::count()`
2. `$density = GameConfig::get('world.density.casinos_per_tile')` → 0.004
3. `$targetCount = round($totalTiles * $density)`
4. `$existingCount = Tile::where('type', 'casino')->count()`
5. `$needed = max(0, $targetCount - $existingCount)`
6. Select candidate wasteland tiles OUTSIDE spawn band: `WHERE type='wasteland' AND (x*x + y*y) > spawn_band_radius²`
7. Use RngService to pick `$needed` distinct indices from candidates
8. If `--dry-run`: output count and sample coordinates, exit
9. In DB::transaction: update tile type to 'casino', bulk-insert casino companion rows with flavor names
10. Idempotent — safe to run multiple times (checks existing count)

---

## 8. Controllers & Routes

### Web Controllers (Inertia)
- `CasinoController` — show (lobby), enter (pay fee), leave
- `Casino/SlotsController` — show, spin
- `Casino/RouletteController` — show, placeBet
- `Casino/BlackjackController` — show, join, bet, action, leave
- `Casino/HoldemController` — show, join, action, leave
- `Casino/ChatController` — send

### API Controllers (mirror under Api/V1/Casino/)
Same endpoints for future mobile client.

### Route Structure
```
/casino              GET  → lobby
/casino/enter        POST → pay entry fee
/casino/leave        POST → exit
/casino/slots        GET  → slot machine
/casino/slots/spin   POST → spin
/casino/roulette/{tableId}      GET  → table view
/casino/roulette/{tableId}/bet  POST → place bet
/casino/blackjack/{tableId}          GET  → table view
/casino/blackjack/{tableId}/join     POST
/casino/blackjack/{tableId}/bet      POST
/casino/blackjack/{tableId}/action   POST
/casino/blackjack/{tableId}/leave    POST
/casino/holdem/{tableId}          GET
/casino/holdem/{tableId}/join     POST
/casino/holdem/{tableId}/action   POST
/casino/holdem/{tableId}/leave    POST
/casino/table/{tableId}/chat      POST
```

---

## 9. Frontend Components

### Pages (`resources/js/Pages/Casino/`)
- `Lobby.vue` — entry fee gate, game list, active table counts
- `Slots.vue` — reel animation, bet input, spin button, win display
- `Roulette.vue` — betting board, chip placement, countdown, wheel animation, results
- `Blackjack.vue` — card display, action buttons, dealer hand, results
- `Holdem.vue` — oval table layout, seats, hole cards, community cards, pot, action buttons + raise slider, timer

### Shared Components (`resources/js/Components/Casino/`)
- `Card.vue`, `CardHand.vue` — playing card rendering
- `ChipSelector.vue` — bet amount input
- `CurrencyToggle.vue` — cash/oil switch
- `RouletteWheel.vue`, `RouletteBoard.vue` — wheel + betting grid
- `SlotReel.vue` — animated reel
- `TableChat.vue` — chat sidebar (group games)
- `PlayerSeat.vue` — seat display with avatar/stack/cards
- `PotDisplay.vue` — pot + side pots (Hold'em)
- `TurnTimer.vue` — countdown
- `CasinoNav.vue` — back to lobby / leave

### Pinia Store
`resources/js/stores/casinoTable.ts` — manages WebSocket subscriptions for active table. Necessary because casino games are real-time and can't rely on Inertia's request-response cycle for every game event.

---

## 10. Security Considerations

### Card information leakage (critical for Hold'em)
- Hole cards sent ONLY via private user channel, never table channel
- `state_json` contains full deck — API responses MUST strip invisible cards via `sanitizeStateForPlayer()` method
- Community cards and showdown reveals go to table channel

### Currency manipulation prevention
- Every bet/payout inside `DB::transaction()` with `lockForUpdate()` on player row
- Hold'em buy-in/cash-out are locked transactions (wallet ↔ stack)
- Stack in `casino_table_players` is canonical during active poker hands
- Validate all bet amounts server-side: min/max from config, sufficient balance, not negative

### Race condition prevention
- Roulette: check `round_expires_at` inside transaction — reject bets after window closes
- Hold'em/Blackjack: validate it's the acting player's turn via `state_json.current_seat` inside lock
- `casino_table_players` unique constraints prevent double-seating

### Rate limiting
- Slots: minimum interval between spins (configurable)
- Chat: 10 messages/minute/player (configurable)
- Roulette: max bets per round (configurable, default 20)
- All within existing throttle middleware

---

## 11. Build Sequence

**Implementation Status:** Phases C1–C6 complete. Ready for VPS deploy + migration run.

### Phase C1: Foundation
1. Migration: add `casino` to tiles enum
2. Migration: create `casinos` table
3. Config: add `casino` section to `config/game.php` + `casinos_per_tile` to `world.density`
4. Model: `Casino` (follows Post/OilField pattern)
5. Tile model: add `casino()` HasOne relationship
6. WorldService: add casino to `rollTileSpec()` cascade + `persistTilePlan()` + `pickCasinoName()`
7. MapStateBuilder: add `'casino'` case to `tileDetail()` match + `casinoDetail()` method
8. Artisan command: `casino:retrofit`
9. CasinoException class

### Phase C2: Casino Entry + Slots
10. Migration: `casino_sessions`, `casino_rounds`, `casino_bets`
11. CasinoService (entry fee, sessions)
12. SlotMachineService (complete game logic)
13. Web + API CasinoController (enter/leave)
14. Web + API SlotsController (spin)
15. Frontend: Lobby.vue, Slots.vue, SlotReel.vue, ChipSelector.vue, CurrencyToggle.vue

### Phase C3: Roulette
16. Migration: `casino_tables`, `casino_table_players`
17. RouletteService (full game loop)
18. Broadcast events (BettingWindowOpened, BetPlaced, RouletteResult)
19. Presence channel: `casino.table.{tableId}` in channels.php
20. Job: ResolveRouletteRound
21. Web + API RouletteController
22. Frontend: Roulette.vue, RouletteWheel.vue, RouletteBoard.vue
23. Pinia store: casinoTable.ts

### Phase C4: Blackjack
24. BlackjackService (full game loop, deck management)
25. Broadcast events (HandDealt, PlayerAction, DealerTurn, Payout)
26. Web + API BlackjackController
27. Frontend: Blackjack.vue, Card.vue, CardHand.vue

### Phase C5: Texas Hold'em
28. HandEvaluator (pure class, unit test FIRST)
29. HoldemService (full state machine, blinds, side pots, rake)
30. Broadcast events (HoleCards via private channel, CommunityCards, PlayerAction, Showdown, TurnTimer)
31. Job: HoldemTurnTimeout
32. Web + API HoldemController
33. Frontend: Holdem.vue, PlayerSeat.vue, PotDisplay.vue, TurnTimer.vue

### Phase C6: Chat + Polish
34. Migration: `casino_chat_messages`
35. CasinoChatService
36. Broadcast event: TableChatMessage
37. ChatController (Web + API)
38. Frontend: TableChat.vue — integrated into Roulette, Blackjack, Holdem pages
39. CasinoTableManager (auto-create, cleanup)
40. Filament admin resources for tables/rounds/bets
41. Activity log integration (big wins/losses)

---

## 12. Verification Plan

1. **Unit tests**: HandEvaluator (all hand rankings), SlotMachineService (payout math), RouletteService (bet validation, payout calculation), BlackjackService (hand values, dealer logic)
2. **Feature tests**: CasinoService (entry fee deduction, session creation), WorldService (casino tiles appear in generation), casino:retrofit command
3. **Manual testing on VPS**: End-to-end casino flow — enter, play each game, verify currency changes, verify WebSocket events fire
4. **RNG verification**: Enable record mode, verify all casino rolls go through RngService with correct categories
5. **Security test**: Verify Hold'em API responses don't leak other players' hole cards
