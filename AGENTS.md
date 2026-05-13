# Codex Project Instructions

This project was originally guided by `CLAUDE.md`. Treat this file as the Codex-native equivalent and keep `CLAUDE.md` as historical context.

## Required Reading

Read these files before non-trivial work:

1. `ultraplan.md` - master gameplay and product reference.
2. `gameplay-ultraplan.md` - locked gameplay rules and balance intent.
3. `technical-ultraplan.md` - architecture, service boundaries, API pattern, deployment notes.
4. Relevant feature notes such as `feature-improvements-1.md`, `feature-improvement-2.md`, `loot-crates.md`, `mobile-improvements.md`, and `casino-feature.md` when working near those systems.

## Current Shape

This is now an implemented Laravel game, not just a planning folder. The app includes world generation, fog of war, movement, shops, items, drilling, combat, spying, sabotage, MDNs, bots, loot crates, base relocation, casino play, activity logs, broadcasting, and Pest coverage.

Stack: Laravel 11, PHP 8.3, Vue 3, Inertia v2, Tailwind 3, Pinia for ephemeral UI state, MySQL, Redis/Reverb-ready, Filament admin, Sanctum API surface.

## Non-Negotiable Patterns

- Balance values, costs, cooldowns, probabilities, caps, and formulas go through `GameConfigResolver` and `config/game.php` defaults. Avoid new hardcoded gameplay constants.
- Random outcomes go through `RngService`; never use `rand()`, `mt_rand()`, or `random_int()` in game logic.
- Game logic belongs in `app/Domain/*`. Web and API controllers stay thin and call the same services.
- Keep web and `/api/v1/*` behavior aligned when adding player actions.
- Use Pest tests for behavior changes. Prefer focused tests for the touched service or feature.
- Run `vendor/bin/pint --dirty --format agent` after PHP edits.
- The development machine is Windows. Use commands and paths that work in PowerShell.

## Store And Item Changes

When changing shop items, keep these in sync:

- `database/seeders/ItemsCatalogSeeder.php`
- `config/game.php` when the value should be admin-tunable
- `ShopService` for purchase rules and immediate effects
- `PassiveBonusService` for passive effects
- `MapStateBuilder` and `resources/js/Pages/Game/Map.vue` for category/effect display
- Bot shopping heuristics if bots should buy the item
- Pest coverage proving the exact gameplay effect

After catalog changes, production needs `php artisan db:seed --class=Database\\Seeders\\ItemsCatalogSeeder --force` so new records land in `items_catalog`.

## Lessons Carried Forward

- Test the exact failure class, not only the happy path. If balance is wrong because one stat cannot keep up with another, assert the catalog and resulting gameplay odds.
- For stealth/security work, compare reachable item totals against `stats.hard_cap`; the shop catalog is part of combat balance.
- Keep feature history docs concise but durable. If a future session changes a major system, add the reason and verification path to the relevant root feature note.
- Do not use subagents unless the user explicitly asks for parallel agents.
