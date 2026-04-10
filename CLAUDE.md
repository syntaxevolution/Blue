# CLAUDE.md — Project Context for Claude Code

**READ THIS FIRST** at the start of every session. This file gets you up to speed on the project.

---

## Project: CashWars Reimagined

A modern remake of the classic ~2001 browser game **CashWars** (set on the fictional oil world of **Akzar**). Persistent-world, turn-limited strategy game where players explore a fog-of-war grid, drill oil, upgrade four balanced stats, spy on and raid rival bases for virtual currency, and form alliances.

**Virtual currency only** — no real money, no play-to-earn. The original died because combat RNG was too flat and ad-farming bots destroyed the economy. We are fixing both.

---

## Required Reading (in this order)

Before doing ANY work on this project, read these three files in the project root:

1. **`ultraplan.md`** — Master reference. Elevator pitch, what-stays/changes/goes, locked parameters, locked tech stack, build phases, glossary.
2. **`gameplay-ultraplan.md`** — Locked gameplay and world design (v1.0). Stats, combat rules, map, fog of war, shops, MDNs, drill mechanics, economy, seasons, onboarding.
3. **`technical-ultraplan.md`** — Full technical architecture (v0.1). Stack, DB schema, domain services, config system, RNG service, API design, deployment, anti-abuse, v1 launch checklist.

**Do not start implementation work without reading all three.** The specs are dense but tight — everything you need is in them.

---

## Tech Stack (locked)

- **Backend:** Laravel 11, PHP 8.3+, MySQL 8, Redis 7
- **Frontend:** Vue 3 (Composition API) + Inertia.js (SPA mode) + Tailwind CSS + Pinia (UI state only) + Vite
- **Real-time:** Laravel Reverb (fallback: Pusher free tier)
- **Queues:** Laravel Horizon
- **Auth:** Fortify (email/pass) + Socialite (Google, Discord, Apple) + Sanctum (mobile API tokens)
- **Admin:** Filament
- **Bot defense:** Cloudflare Turnstile
- **Email:** Resend or Postmark (TBD)
- **Testing:** Pest

---

## Environment

- **Development machine:** Windows (use Write/Read tools for file ops, avoid Linux-only shell commands)
- **Production server:** Debian stable VPS running **DirectAdmin**, shared with other side projects
  - Will migrate to dedicated VPS if the game succeeds
  - Nginx, Supervisor, Let's Encrypt SSL
  - Manual deploy via `git pull` + shell script, no CI/CD
- **Target launch size:** ~100 users
- **Local dev preference (leaning):** Laravel Herd for Windows

---

## Core Architectural Principles

1. **Configurability is a product feature.** Every balance number, cost, cooldown, RNG range, and probability lives in config (`config/game.php` defaults + `game_settings` DB table overrides), accessed via a `GameConfig` facade. **Never hardcode a balance value.** Tuning the game must never require a deployment.
2. **Seeded RNG via `RngService`.** Every random roll goes through the service. It supports record mode (for dispute audits), replay mode (for deterministic tests), and configurable PRNG sources.
3. **Dual-layer controllers, shared domain.** `app/Http/Controllers/Web/*` for Inertia, `app/Http/Controllers/Api/V1/*` for REST (future mobile). Both are thin and call the same `app/Domain/*` services. All game logic lives in the domain layer, which is pure PHP and fully testable.
4. **Lazy state reconciliation.** Move regen and oil field regen compute on-read, not via global ticking jobs. Zero drift.
5. **Single-VPS sanity.** Everything fits on one box for 100 users. No microservices, no K8s, no distributed anything.

---

## Locked Gameplay Parameters (quick reference)

| Parameter | Value |
|---|---|
| Stats | Strength, Fortification, Stealth, Security |
| Stat hard cap / soft plateau | 25 / starts at 15 |
| Drill grid per oil field | 5×5 (25 points) |
| Loot ceiling per raid | 20% |
| Cash floor | None (players can be robbed to zero) |
| Bankruptcy pity stipend | A0.25, once per event |
| New player immunity | 48 hours from signup |
| Spy intel decay | 24 hours |
| Intel decay | 1% per day |
| Intel value anchor | 1 Intel ≈ 5 barrels |
| MDN size cap | 50 |
| Same-MDN attacks | Blocked |
| MDN formal alliances | Declarative UI only |
| Raid cooldown per target | 12 hours per attacker |
| Combat formula | Deterministic + ±10–15% RNG |
| Season model | Soft monthly, no resets, cosmetic rewards |
| Starting cash / stats / drill | A5.00 / Str 1 others 0 / Dentist Drill |
| Daily move regen | ~200 (configurable) |

**Every single one of these is a config key — tune, don't hardcode.**

---

## Build Phases

1. **Phase 0 — Foundations:** Laravel project, auth, `GameConfig`, `RngService`, admin panel skeleton
2. **Phase 1 — World & Movement:** tiles, fog of war, spawn, travel
3. **Phase 2 — Economy:** oil fields with 5×5 drill grids, posts, shops, items, currencies
4. **Phase 3 — Combat:** spy, attack, CombatFormula, bankruptcy, notifications
5. **Phase 4 — Social:** MDNs, shared journal, alliances
6. **Phase 5 — Progression & Polish:** leaderboards, seasons, world growth, auctions, tutorial
7. **Phase 6 — Launch Prep:** anti-abuse, load test, deploy script, docs, beta

See `ultraplan.md` §7 for details.

---

## Current State

**Planning complete. No code written yet.**

All three Ultraplan docs are finalized and locked. The next step is **Phase 0 — Foundations**: bootstrap the Laravel project.

### Open decisions to resolve at start of Phase 0
(From `technical-ultraplan.md` §20)

1. Laravel Herd vs Sail for Windows local dev — **leaning Herd**
2. Filament vs custom admin — **leaning Filament**
3. Resend vs Postmark for email — **leaning Resend**
4. Disposable email blocklist source
5. Scheduler via `schedule:work` vs cron — **leaning cron on DirectAdmin**

None are blockers; can be resolved inline while bootstrapping.

---

## Working Rules for Claude Code on This Project

1. **Always read the three Ultraplan docs before any work.** Scope and decisions live there.
2. **Never hardcode a balance value.** If you find yourself typing `5` or `0.2` into game logic, stop and add a config key instead.
3. **All RNG through `RngService`.** No direct `rand()` or `random_int()` calls in game logic.
4. **Domain logic goes in `app/Domain/*`, never in controllers.** Controllers are thin; services are pure.
5. **Web and API must stay in sync.** When adding a new action, add both the Inertia controller and the `/api/v1/*` REST controller, and put the logic in a shared service.
6. **Windows dev machine.** Use Write/Read tools for files. Shell commands should work on Windows (cmd or git-bash). Avoid Linux-only syntax unless SSH'd into the prod server.
7. **No CI/CD.** Deployment is manual via `git pull` + shell script on the Debian VPS.
8. **Target is 100 users on a shared VPS.** Don't over-engineer for scale we don't have yet.
9. **Ask before making architectural changes** to the locked stack or the locked gameplay parameters. Minor tuning is expected; structural changes need sign-off.
10. **Keep the Ultraplan docs in sync.** If a decision changes during implementation, update the relevant doc so the master spec stays authoritative.

---

## File Map

- `CLAUDE.md` — this file
- `ultraplan.md` — master reference
- `gameplay-ultraplan.md` — locked gameplay and world design (v1.0)
- `technical-ultraplan.md` — full technical architecture (v0.1)

The Laravel project will be bootstrapped into this same folder (or a subfolder — TBD at start of Phase 0).

---

## When Resuming a Session

1. Read `CLAUDE.md` (this file)
2. Read the three Ultraplan files
3. Check the current state of the project folder to see what's been built
4. Confirm understanding back to the user
5. Ask what they'd like to work on next, or pick up where the last session left off
