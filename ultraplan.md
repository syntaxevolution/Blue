# CashWars Reimagined — Master Ultraplan

A reimagining of the classic browser game CashWars (Akzar, ~2001), built for the modern web.

This document is the **master reference**. For detail, see the companion files:
- **`gameplay-ultraplan.md`** — locked gameplay and world design (v1.0)
- **`technical-ultraplan.md`** — full technical architecture (v0.1)

---

## 1. Elevator Pitch

CashWars Reimagined is a persistent-world, browser-native, turn-limited strategy game set on the dusty oil world of **Akzar**. Players explore a fog-of-war grid, drill for oil, upgrade four balanced stats (Strength, Fortification, Stealth, Security), spy on and raid rival bases for Akzar Cash, and band together into Mutual Defense Networks (MDNs). The world grows as the population grows, the economy is entirely virtual, combat rewards planning over luck, and every knob of balance is tunable live from an admin panel without a deploy.

Built in **Laravel 11 + Vue 3 + Inertia.js + Tailwind** on a single Debian VPS, with an **API-first architecture** so a mobile client can be plugged in later without refactor.

---

## 2. What CashWars Was

The original CashWars (1999–2001) was a browser-based massively multiplayer game set on the fictional world of Akzar. Players had a small daily move budget to wander a grid map, drilling random oil fields, shopping at stat posts, spying on and attacking rival bases. Upgrades came in four categories (Strength, Fortification, Stealth, Security). Alliances were called Mutual Defense Networks. The game let players cash out Akzar Cash to real USD at A3.00 = $1.00 with a $20 minimum — the first "play to earn" attempt on the web.

It died within ~18 months because combat was too RNG-heavy for wealth to snowball (making the cashout unreachable) and because the ad-driven move economy was destroyed by bot farms.

**We are rebuilding it properly.**

---

## 3. What Stays, What Changes, What Goes

### ✅ Stays — the classic soul
- Fog-of-war grid exploration with no overview
- Static stat posts you must travel to
- Four stats: Strength, Fortification, Stealth, Security
- Oil barrels as the upgrade currency
- Akzar Cash as the wealth/loot currency
- Spy-before-attack as a gameplay ritual
- MDN alliance system (now capped at 50)
- Persistent single shared world
- Tile types: bases, oil fields, posts, wasteland, landmarks
- "Moving Company" and "Satellite Locator" as endgame auction items

### 🔧 Changes — rebalanced and modernized
- **Deterministic combat** with a tight RNG band — no more flat-wealth RNG trap
- **Stat soft plateau** (cap 25, plateau starts at 15) — spread beats spike
- **20% loot ceiling per raid** — no one-shot wipes
- **Intel as a 4th currency** — espionage has real economic weight
- **Oil fields contain a 5×5 drill-point sub-grid** — drill location matters
- **24-hour spy intel decay** — forgiving for weekend players
- **Move regen is continuous trickle**, not daily dump
- **World auto-grows outward in rings** as population rises
- **MDNs cap at 50** and same-MDN attacks are hard-blocked
- **Shared journal with voting** as a purchased MDN upgrade
- **Monthly soft seasons** with cosmetic-only rewards, no wipes

### ❌ Goes — the killers
- No real-money cashout, no play-to-earn
- No ad-click move bonuses (sponsor offers exist but capped at % of baseline)
- No hidden stat diminishing returns that punished wealth accumulation
- No pay-to-win
- No blind attacks (spying is mandatory)

---

## 4. Locked Gameplay Parameters

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
| MDN formal alliances | Declarative UI only |
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

Every single one of these values lives in **configuration**, not code. They can be tuned live from the admin panel without a deployment.

---

## 5. Locked Technical Stack

| Layer | Choice |
|---|---|
| Runtime | PHP 8.3+ |
| Framework | Laravel 11 |
| Frontend | Vue 3 + Inertia.js (SPA mode) + Tailwind CSS |
| State | Pinia (ephemeral UI state only) |
| Build | Vite |
| Database | MySQL 8 |
| Cache / Queue / Pub-Sub | Redis 7 |
| Queue runner | Laravel Horizon |
| WebSockets | Laravel Reverb (fallback: Pusher free tier) |
| Auth | Fortify (email/pass) + Socialite (Google, Discord, Apple) + Sanctum (mobile) |
| Admin panel | Filament |
| Email | Resend or Postmark |
| Bot defense | Cloudflare Turnstile |
| Web server | Nginx |
| Process supervisor | Supervisor |
| Testing | Pest |
| OS (prod) | Debian stable |
| Control panel (prod) | DirectAdmin |
| Local dev (Windows) | Laravel Herd |
| Deployment | Manual git pull + shell script |
| Target launch size | ~100 users |
| Hosting | Single VPS shared with other side projects, migrate to dedicated later |

---

## 6. Core Architectural Principles

1. **Configurability is a product feature.** Every balance number, cost, cooldown, RNG range, and probability lives in config — never hardcoded. A `GameConfig` facade resolves from DB overrides → shipped defaults, with Redis caching and admin-panel audit trail.
2. **Seeded RNG with optional audit logging.** Every random roll goes through `RngService`. Rolls can be recorded for dispute investigation, replayed deterministically in tests, and swapped between PRNG implementations via config.
3. **Dual-layer controllers, shared domain.** Inertia controllers for web and REST controllers for `/api/v1/*` are both thin. All game logic lives in `app/Domain/*` services that are pure PHP and fully unit-testable.
4. **Lazy state reconciliation.** Move regen and oil field regen are computed on-read, not by global ticking jobs. Zero drift, minimal background load.
5. **Small-scale sane defaults.** Everything fits on one Debian VPS for 100 users. No Kubernetes, no microservices, no distributed anything until scale demands it.

---

## 7. Build Phases (rough)

### Phase 0 — Foundations (week 1)
- Laravel project, Vue/Inertia/Tailwind wired
- MySQL schema migrated
- Auth: email/pass + Socialite Google/Discord/Apple
- `GameConfig` facade + `game_settings` table + Filament admin panel
- `RngService` with seeded rolls
- Basic user profile with immutable username

### Phase 1 — World & Movement (week 2)
- World generation (initial ring)
- Tile model + fog-of-war engine
- Player spawn + base claim
- Travel action (N/S/E/W)
- Tile view: current tile + edge hints + journal

### Phase 2 — Economy (week 3)
- Oil fields with 5×5 drill-point grids
- Drilling action + equipment tiers
- Barrel + cash + intel currencies
- Posts (5 types) + General Store with item catalog
- Purchase flow
- Item effects applied to player stats

### Phase 3 — Combat (week 4)
- Spy action (3 depths) + intel earning
- Attack action + CombatFormula
- Loot calculation with 20% ceiling and no floor
- Bankruptcy state + pity stipend
- Attack/spy notifications via Reverb

### Phase 4 — Social (week 5)
- MDN creation, join, leave, roles
- Same-MDN attack/spy blocking
- Shared journal (purchased upgrade) with voting
- MDN formal alliances (declarative)

### Phase 5 — Progression & Polish (week 6)
- Leaderboards (weekly jobs)
- Seasons (monthly rollover, cosmetic rewards)
- World growth job
- Abandoned base → ruin decay
- Auction House with endgame items
- Tutorial + NPC dummy base
- 48h new-player immunity

### Phase 6 — Launch Prep (week 7)
- Admin panel polish
- Anti-abuse: Turnstile, rate limiting, action logs
- Load test at 100 concurrent users
- Deploy script + backup cron
- Docs: admin runbook, deployment runbook, config reference
- Private beta → open launch

Timing is indicative — the actual sequence can compress or expand as priorities shift.

---

## 8. Decisions Still to Resolve Before Build

From the Technical Ultraplan §20:

1. Laravel Herd (recommended) vs Sail for Windows local dev
2. Filament (recommended) vs custom admin
3. Resend (recommended) vs Postmark for email
4. Disposable email blocklist source
5. Scheduler: `schedule:work` vs cron (leaning cron)
6. Any other decisions that come up during Phase 0

---

## 9. File Map

- `ultraplan.md` — this file, the master reference
- `gameplay-ultraplan.md` — locked gameplay and world design
- `technical-ultraplan.md` — full technical architecture

All three live at the root of this project folder and are version-controlled alongside the code.

---

## 10. Glossary

- **Akzar** — the fictional dust-choked oil world the game is set on
- **Akzar Cash (A)** — in-game wealth currency, loot target
- **Oil Barrel** — upgrade currency, earned by drilling
- **Intel (I)** — espionage currency, earned by spying
- **Base** — a player's home tile holding cash, items, fortifications
- **Post** — a static tile where players buy category-specific upgrades
- **Oil Field** — a tile containing a 5×5 grid of drill points
- **Drill Point** — one of 25 sub-locations within an oil field, randomly seeded quality
- **MDN (Mutual Defense Network)** — alliance of up to 50 players
- **Spy Depth** — how many successful spies an attacker has on a target (1–3)
- **Bankruptcy** — state when a player's cash reaches A0.00; they get a one-time pity stipend and can rebuild
- **Fog of War** — players only see their current tile and adjacent edge hints by default
- **Settler Protection** — the 48-hour raid immunity new players get on signup
- **Soft Season** — monthly cosmetic-reward cycle with no stat/economy wipes
