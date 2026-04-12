# CashWars Reimagined — Gameplay Ultraplan v1.0 (LOCKED)

A classic-faithful reimagining of CashWars: browser-native, virtual-currency only, persistent world of Akzar, balanced combat, fog-of-war exploration, API-first so a mobile client can plug in later.

---

## 1. Design Pillars

1. **Exploration is the game.** You don't know what's out there until you walk there. Discovery has value, maps are commodities.
2. **Every stat matters.** No dominant build. Strength, Stealth, Fortification, and Security each gate a distinct piece of the loop.
3. **Recon before risk.** You must scout before striking. Spying is a gameplay verb, not a menu.
4. **Patience meets opportunism.** Regen ticks slowly; sponsor bonuses reward engaged players; smart moves beat brute force.
5. **The world breathes.** The map grows as the population grows, so it's always dense enough to be interesting and never so empty it feels dead.
6. **Classic soul, modern balance.** Keep the feel (grids, travel, stat posts, MDNs, oil barons) while fixing the flat-wealth RNG trap that killed the original.

---

## 2. The World of Akzar

**Setting.** Akzar is a dust-choked frontier world rich in crude oil, abandoned by its original colonial charter after a market collapse. What remained were the drillers, the mercenaries, and the opportunists — squatters who turned Akzar into a lawless economy built entirely on oil barrels and stolen cash.

**Tone.** Grimy, industrial, slightly retro-futurist. Mad Max oil field crossed with a 1930s company town, dotted with homebrew fortified compounds and flickering neon "post" signs advertising Strength, Stealth, Fort, and Parts.

**Factions (lore flavor for later):**
- **The Drillheads** — oil obsessives, revere equipment
- **The Ghosts** — stealth and recon purists
- **The Hold** — fortification-first homesteaders
- **Freelancers** — unaligned raiders (most new players)

Factions are narrative/cosmetic at launch, potential mechanical hook later.

---

## 3. The Map

### 3.1 Structure
- **Grid-based**, `Nn Ee` coordinate system centered at origin
- **Single shared persistent world.** No shards at launch
- **Tile types:**
  - **Player Bases** (one per player, claimed on signup)
  - **Oil Fields** (drillable, regen over time, contain 5x5 drill-point sub-grids)
  - **Stat Posts** (Strength / Stealth / Fort / Tech / General Store)
  - **Wasteland tiles** — empty, flavor text
  - **Landmarks** — rare static points of interest
  - **Auction House** — one static far-from-spawn location

### 3.2 Fog of War
- Players see **only their current tile** and an edge hint
- **No minimap, no overview** by default
- Players can take **personal notes** on tiles via an in-game journal (free)
- **Maps are purchasable items** with tiers

### 3.3 Auto-Growing World
- **Start size:** small ring around origin, sized for ~50–100 active players
- **Growth trigger:** when active population × desired-tiles-per-player exceeds current map area, the world expands outward in rings
- **Density targets:**
  - 1 Oil Field per ~8 tiles
  - 1 Post (any type) per ~40 tiles
  - 1 Landmark per ~200 tiles
  - Remainder = wasteland/flavor
- **Rings never shrink.** Abandoned bases decay into ruin landmarks
- **Frontier bias:** new players spawn closer to origin; expansion happens outward

### 3.4 Oil Field Drill-Point Sub-Grid
- Each Oil Field contains a **5×5 = 25 drill-point sub-grid**
- Each drill point is randomly seeded per regen cycle as one of:
  - **Dry** (no oil, move wasted)
  - **Trickle** (small yield)
  - **Standard** (normal yield)
  - **Gusher** (large yield, rare)
- Players cannot see quality before drilling
- Better drill equipment narrows bad outcomes
- "Seismic Reading" consumables reveal one point before committing

---

## 4. Resources & Currencies

| Resource | Role | How earned | How spent |
|---|---|---|---|
| **Oil Barrels** | Upgrade currency | Drilling oil fields | Stat items, drill upgrades, store goods |
| **Akzar Cash (A)** | Wealth/score, attack loot | Starting stipend, stealing from players, rewards | Auctions, premium store items, services |
| **Moves** | Action economy | Daily regen + sponsor bonus | Travel, drill, spy, attack, shop |
| **Intel (I)** | Espionage currency | Spying bases and landmarks | Targeted tile reveals, hit contracts, MDN perks, counter-intel |

Virtual-only. **No cashout.** Leaderboards and seasonal accolades replace the money incentive.

**Starting loadout:** A5.00, Dentist Drill, Strength 1, everything else 0.

**Intel value anchor:** 1 Intel ≈ 5 barrels utility.
**Intel decay:** 1% per day to prevent hoarding.

---

## 5. Player Stats

1. **Strength** — Attack power. Damage and loot % on successful raid.
2. **Fortification** — Base defense. Reduces loot stolen, increases attacker flee rate.
3. **Stealth** — Spying power + raid escape. Spy success and post-attack escape.
4. **Security** — Counter-intel. Reduces accuracy of enemy spies, can feed false data.

### 5.1 Stat Caps
- **Hard cap per stat: 25**
- **Soft plateau begins at: 15**
- **Scaling curve:**
  - Levels 1–15: linear, clear returns
  - Levels 16–20: ~60% efficiency
  - Levels 21–25: ~30% efficiency

### 5.2 Balance Philosophy (fixing the RNG trap)
- **Deterministic core + RNG band.** Outcome = deterministic calc with ±10–15% random band
- **Stat rock-paper-scissors:**
  - Strength beats Fortification (diminishing)
  - Stealth beats Security (diminishing)
  - Fortification hard-counters low-Stealth attackers (can't escape)
  - Security hard-counters low-Stealth spies (you learn who tried)
- **Spread beats spike.** A 5/5/5/5 player outperforms 10/0/5/5
- **Loot ceiling per raid: 20%** of target's cash
- **No cash floor.** Players can be robbed to zero

### 5.3 Bankruptcy State
- Triggered when Akzar Cash = A0.00
- Base tagged "BANKRUPT" on tile view
- Attackers see a warning and gain nothing on success
- **Pity stipend:** A0.25 delivered once per bankruptcy event on next daily tick
- Stats, Intel, Oil, items, drill equipment, fortifications all preserved
- Exit automatically on any positive cash balance

---

## 6. Core Loop

**Per session:** Check base, spend moves, travel + drill/spy/attack, return to post to upgrade, log off with a plan

**Per day:** Moves regenerate, sponsor bonuses available, oil fields partially replenish, daily leaderboards tick, rich base list refreshes

**Per week:** MDN standings, weekly events, store rotations

**Per season (~1 month):** Soft season leaderboard locks with cosmetic rewards, economy tuning, no wipes

---

## 7. Actions

| Action | Moves | Notes |
|---|---|---|
| Travel 1 tile | 1 | Straight N/S/E/W, no diagonals |
| Drill | 2 | Only on oil fields |
| Spy on base | 3 | Requires Stealth ≥ 1 |
| Attack base | 5 | Requires prior successful spy within 24 hours |
| Shop / browse post | 0 | Free on the tile |
| Plant journal note | 0 | Free |
| Fast-travel to own base | variable | Via "Home Signal" purchased item |

All costs configurable.

---

## 8. Spy-Before-Attack Ritual

- **Required gating:** attack requires at least one successful spy within last 24 hours
- **Deeper recon = better outcomes:**
  - 1 spy: attack unlocked, blind to defenses
  - 2 spies: see defender's cash estimate and Fort level
  - 3 spies: guaranteed escape — even on a loss, no cash lost
- **Counter-spy counterplay:** high Security can detect spy attempts, log attackers, feed false intel
- **Intel decays in 24 hours.** Raiders must maintain active reconnaissance

---

## 9. Posts & Shops

Posts are **static, scattered, and must be traveled to**. Coordinates never move once generated.

### 9.1 Post Types
- **Strength Post** — weapons, melee to mechanized
- **Stealth Post** — camo, distraction gadgets, escape tools
- **Fortification Post** — locks, walls, guard systems
- **Tech/Parts Post** — drill upgrades, base utilities
- **General Store** — consumables, maps, QoL items
- **Auction House** — rare and endgame gear (single central location)

### 9.2 Strength Items (sample)
Small Rock, Boulder, Blackjack, + higher tiers up to mechanized equipment

### 9.3 Stealth Items (sample)
Boots, Sneakers, Firecracker Distraction, + higher tiers

### 9.4 Fortification Items (sample)
Door Latch, Simple Lock, Reinforced Doors, Guardbot, + higher tiers

### 9.5 Security Items (sample)
Surveillance, decoy bases, counter-intel modules

### 9.6 Drill Equipment Ladder
| Tier | Name | Yield Band | Special |
|---|---|---|---|
| 1 | Dentist Drill | 1–3 | Starter, heavy variance |
| 2 | Shovel Rig | 2–5 | |
| 3 | Medium Drill | 4–9 | |
| 4 | Heavy Drill | 7–14 | |
| 5 | Industrial Rig | 10–20 | No "Dry" points |
| 6 | Refinery | 15–30 | Guarantees one Standard+ point per field |

### 9.7 General Store Items
- **Paper Map (tier I–III)** — reveal tiles in a radius
- **Journal Binder upgrades** — more notes, tags, waypoint alerts
- **Home Signal** — fast-travel home, limited charges
- **Oil Canister** — portable oil storage
- **Compass** — direction to nearest post type
- **Rumor Ticket** — hint at a rich base location
- **Decoy Base Kit** — plants fake base as bait
- **Fuel Can** — small one-time move refill
- **Messenger Drone** — send a message without knowing location
- **Trail Marker** — pin a tile publicly (MDN-visible)
- **Scrap Crate** — RNG loot box of low-tier parts
- **Bribe Pass** — bypass a single post gate
- **Seismic Reading** — reveals one drill point's quality before committing

### 9.7.1 Sabotage Devices & Counter Measures (v1.1)
Deployable trap items planted on individual drill points (one per cell) and their counters. Placed via the Toolbox HUD while standing on an oil field. All values live in `config/game.php` under `items.*` and `sabotage.*` — fully tunable from the Filament admin panel.

- **Gremlin Coil** — 500 barrels, stackable. Plant on a drill point; the next non-owner driller to hit it has their rig wrecked. Tier-1 starter drills are immune to the break. Does not steal oil.
- **Siphon Charge** — 5000 barrels, stackable. Like the Gremlin Coil, but ALSO siphons 50% of the victim's oil straight to the planter (uncapped). The siphon still fires against tier-1 drillers (rig safe, oil still stolen).
- **Tripwire Ward** — 100 barrels, stackable, passive consumable counter. Having ≥1 in your toolbox auto-consumes one on contact with any armed trap: the trap is marked detected, your rig survives, you lose the move and get zero barrels.
- **Deep Scanner** — 10000 barrels, **single-purchase permanent passive**. While standing on an oil field, rigged cells are revealed on the 5×5 grid with a hazard marker and can't be selected for drilling. Server defensively rejects the drill attempt too.

**Locked rules:**
- One active trap per drill point (hard cap, config-driven)
- Planters see their own traps on the grid and can't drill into them
- Own traps never trigger on the planter (short-circuit to normal yield)
- Placement costs 1 move
- 48h new-player immunity causes a fizzle with **no oil loss** and a "lucky this time" notification
- Tier-1 starter drill is always preserved (can't be broken by a trap)
- Rigs destroyed by sabotage are NOT repairable — must be re-bought at a Tech post
- Traps persist until triggered (no time-based decay at v1; `sabotage.trap_ttl_hours` config hook reserved)
- Sabotage events surface in the Attack Log for players who own the Counter-Intel Dossier, alongside raids

### 9.8 Endgame Auction Items
- **Moving Company** — relocate base
- **Satellite Locator** — advanced scouting

---

## 10. Base Management

- One base per account
- Holds Akzar Cash, installed Fortifications, installed Security, stored Oil, Journal
- **Visible on tile** but contents hidden without spying
- Base relocation via Moving Company auction item
- Abandoned bases (inactive N days) decay into ruin landmarks — lootable once for small reward

---

## 11. Moves / Energy System

- **Passive daily regen:** ~200 moves/day (configurable), continuous trickle not daily dump
- **Move cap:** up to ~1.5–2× daily regen (configurable)
- **Sponsor bonus:** one-time bonus pool per sponsor offer, rotating lineup
- **Sponsor integrity:** capped at percentage of monthly regen to prevent dominance
- **No pay-to-win:** no premium currency buying moves

---

## 12. MDNs (Mutual Defense Networks)

- **Max 50 members per MDN**
- **Same-MDN actions BLOCKED:**
  - Attacking, spying, decoying, placing hit contracts, feeding false intel on members
- **Formal MDN alliances** — declarative/UI only, allied MDNs can still raid each other
- **MDN hop protection:** 24-hour cooldown on attacks after joining/leaving an MDN

### 12.1 MDN Features
- Shared map (tier-gated contributions)
- Shared Journal (purchased upgrade, ratings-sortable most-helpful-first)
- Coordinated attack contracts
- Defensive retaliation pings
- MDN warfare with bonus cash from rival-member raids

---

## 13. Leaderboards & Progression Goals

- **Weekly Raider Board** (most cash stolen)
- **Driller Board** (most oil extracted)
- **Ghost Board** (most successful spies without detection)
- **Warden Board** (most attacks repelled)
- **Richest Baron** (total cash held)
- **Explorer Board** (most tiles discovered)
- **MDN Standings**

**Season rewards: cosmetic only.** Titles, base skins, profile badges, seasonal flavor. No mechanical advantages carry between seasons.

---

## 14. Onboarding

1. **Land on Akzar.** Flavor intro, claim a base tile near core
2. **Your shack.** See stats, journal, A5.00, Dentist Drill
3. **Tutorial compass** (one-time): points to nearest Oil Field and Strength Post
4. **Guided first raid:** NPC dummy base for practice without real PvP risk
5. **Join or skip MDN prompt**

**New player raid immunity: 48 hours from account creation.**

### 14.1 Settler Starter Pack
- 1× Tutorial Compass (one-use)
- 1× Paper Map I (small radius)
- 1× Compass (reusable, charged)
- 1× Seismic Reading
- 1× Fuel Can
- 1× Journal (always free)

---

## 15. Locked Parameters Summary

| Parameter | Value |
|---|---|
| Stats | Strength, Fortification, Stealth, Security |
| Stat hard cap | 25 |
| Stat soft plateau start | 15 |
| Drill grid per oil field | 5×5 (25 points) |
| Drill tier count | 6 (Dentist → Refinery) |
| Loot ceiling per raid | 20% |
| Cash floor | None |
| Bankruptcy pity stipend | A0.25, once per event |
| New player immunity | 48 hours from signup |
| Spy intel decay | 24 hours |
| Intel economy decay | 1% per day |
| Intel value anchor | 1 Intel ≈ 5 barrels |
| MDN size cap | 50 |
| Same-MDN attacks | Blocked |
| MDN federations | Declarative UI only |
| MDN join/leave cooldown | 24 hours before attacks allowed |
| Raid cooldown per target | 12 hours per attacker |
| Season model | Soft, monthly, no resets |
| Season rewards | Cosmetic only |
| Starting cash | A5.00 |
| Starting drill | Dentist Drill |
| Starting stats | Str 1, Fort 0, Stealth 0, Sec 0 |
| Daily move regen (draft) | ~200 |
| Move bank cap | 1.5–2× daily regen |
| Combat formula | Deterministic + ±10–15% RNG |
| World growth | Ring expansion on density trigger |
| Fog of war | Tile-at-a-time, no overview unless mapped |
| Shared journal | Purchased upgrade, ratings-sorted |
| PvE content | Deferred post-launch |

---

## 15.1 Toolbox HUD (v1.1)

A persistent, collapsible dock anchored bottom-right of every authed page. Owned consumables and passive counters are grouped by role (Sabotage / Counter Measures / Utility). On oil field tiles, deployable items get a **Place** action that flips the drill grid into placement mode — click a cell to plant, press Esc or the dock's Cancel button to bail out. Placement mode auto-cancels the moment the player leaves the current oil field. The dock is the intended home for all future consumables (Paper Maps, Fuel Cans, Seismic Readings, etc.) — the current sabotage items are the first citizens.

---

## 16. What We Are NOT Doing

- No cashout, no real money rewards, no play-to-earn
- No pay-to-win energy/stat purchases
- No mandatory alliances
- No global minimap at any tier
- No permadeath or base-wipe attacks
- No blind attacks (spying always required)
- No diagonal movement at v1

---

## 17. Minimum Viable World (v1 scope)

- Persistent grid world with fog of war
- Travel, drill, spy, attack, shop actions
- Four stats, balanced combat formula
- Oil Barrels + Akzar Cash + Intel economies
- Five post types + General Store + Auction House
- Base claim, fortification, journal
- Passive move regen + sponsor bonus
- MDNs with formal alliances
- At least one NPC dummy base for tutorials
- Weekly leaderboards

**Deferred post-launch:** events, weather, factions, caravans, PvE bots, advanced decoys.
