# Cash Clash — Mobile Improvements Plan

**Status:** Planning complete — implementation in progress
**Owner:** Claude Code + PC
**Scope:** Improve mobile browser experience without removing features. Desktop experience must remain untouched or improved.
**Target viewports:** 375×667 (iPhone SE) up through 768×1024 (iPad portrait). Desktop ≥ 1024px stays as-is.

---

## Guiding principles

1. **Keep every feature.** No mobile-only feature cuts.
2. **Layout-only fixes wherever possible.** Domain code, Pinia stores, Echo wiring, and Inertia routes stay untouched.
3. **Mobile-first Tailwind.** Default classes target 375px; `sm:` and up add desktop affordances. Flip the current `hidden sm:flex` mindset where it makes sense.
4. **44px minimum tap target** for anything interactive. Enforce via a reusable `.tap-target` utility.
5. **No JS-based mobile detection.** CSS breakpoints and `@media (hover: none) and (pointer: coarse)` only — avoids Inertia hydration mismatch and keeps pages stateless.
6. **No commits from Claude.** Edits are staged; PC deploys to the Debian/DirectAdmin VPS for testing.
7. **Dark-mode-only stays locked.** All new styles assume `class="dark"` is always on.
8. **MariaDB, no MySQL 8 features.** (Not expected to matter for this work, but noted.)

---

## Baseline findings (from exploration)

- **Mobile-readiness score:** 4/10
- **Top offenders:**
  - `ToolboxDock.vue` — fixed `w-72` blocks 77% of a 375px viewport
  - `Map.vue` — drill grid `w-9 h-9` (36px, below tap minimum), asymmetric direction pad, 5-col stats bar
  - `Mdn/Index.vue` and `Mdn/Show.vue` — hard `min-w-[640px]` / `min-w-[560px]` tables
  - Casino components — fixed card/seat/chat dimensions, entirely desktop-optimized
  - `AuthenticatedLayout.vue` — nav entirely hidden on mobile; only hamburger
  - Badges at `text-[9px]` — unreadable and untappable
  - `hover:` classes everywhere with no touch-device neutralisation
- **Good news:** entirely DOM-based (no canvas to rewrite), viewport meta is correct, Atlas already does touch panning.

---

## Answered open questions

1. **Navigation pattern on mobile:** Bottom tab bar (new), hamburger becomes secondary "More" drawer.
2. **Casino on mobile:** Full M6 redesign — no gating.
3. **Hover neutralisation scope:** `@media (hover: none) and (pointer: coarse)` — touch-only devices, desktops with touchscreens unaffected.

---

## Phased rollout

### Phase M0 — Foundations

High-leverage CSS/utility infra that every later phase reuses.

| # | Task | Files |
|---|---|---|
| M0.1 | Add `@media (hover: none) and (pointer: coarse)` block to `app.css` that disables `:hover` visual states by forcing them back to their base style. | `resources/css/app.css` |
| M0.2 | Add `.tap-target` utility (`min-h-11 min-w-11 inline-flex items-center justify-center`) via `@layer components`. | `resources/css/app.css` |
| M0.3 | Add `.safe-bottom` helper (`padding-bottom: max(env(safe-area-inset-bottom), 0.5rem)`) and `.safe-top` equivalent. | `resources/css/app.css` |
| M0.4 | Add `.scroll-snap-x` helper combining `flex overflow-x-auto snap-x snap-mandatory scrollbar-thin` with a right-edge mask-image fade to hint "more →". | `resources/css/app.css` |
| M0.5 | Extend `tailwind.config.js` with a `touch` screen variant and an `xs: 375px` breakpoint for fine-grained mobile targeting. | `tailwind.config.js` |

**Acceptance:** `app.css` builds without errors, new utilities are usable from Vue templates. No visual changes to desktop.

---

### Phase M1 — App shell & bottom tab bar

Unblocks everything else — users need to reach pages.

| # | Task | Files |
|---|---|---|
| M1.1 | Create `MobileTabBar.vue` component: fixed bottom, 56px tall, 5 items (Map, Atlas, Activity, MDN, More), icons + label text, active state, safe-area padding, `sm:hidden`. | `resources/js/Components/MobileTabBar.vue` (new) |
| M1.2 | Wire tab bar into `AuthenticatedLayout.vue`. Add `pb-16 sm:pb-0` to the `<main>` so content doesn't hide behind it. | `resources/js/Layouts/AuthenticatedLayout.vue` |
| M1.3 | Convert the "More" tab target: opens a slide-in drawer (from right) containing Profile, Settings, Hostility Log, Logout. Reuse existing `Dropdown`/`Modal` where possible; otherwise build a lightweight drawer component. | `resources/js/Components/MobileMoreDrawer.vue` (new), `AuthenticatedLayout.vue` |
| M1.4 | Move the badge counters (Hostility, Activity) into the tab bar so mobile users actually see them. Scale badge text to `text-[10px]` minimum, wrap in `tap-target`. | `MobileTabBar.vue`, `AuthenticatedLayout.vue` |
| M1.5 | Shrink top navbar on mobile: hamburger button removed (replaced by More tab), keep only logo mark + currency display. Desktop navbar unchanged. | `AuthenticatedLayout.vue` |
| M1.6 | Pass `unreadHostility`/`unreadActivity` props through from the existing `useNotifications` composable into the tab bar reactively. | `MobileTabBar.vue`, `useNotifications.ts` (read only) |

**Acceptance:**
- On 375px: bottom tab bar visible, tappable, all 5 routes reachable, badges visible.
- On ≥640px: no visual change.
- No broken hamburger (removed cleanly, its links live in the More drawer or tab bar).

---

### Phase M2 — Map.vue core gameplay screen

The most gameplay-critical screen. 1,184 lines. Refactor presentation only; logic untouched.

| # | Task | Files |
|---|---|---|
| M2.1 | **Stats bar redesign.** Replace `grid grid-cols-2 md:grid-cols-5` with a `scroll-snap-x` horizontal chip row on mobile, `md:grid md:grid-cols-5` on desktop. Each chip: icon + label + value, `min-w-[9rem]`, `snap-center`. | `resources/js/Pages/Game/Map.vue` |
| M2.2 | **Direction pad redesign.** Replace the asymmetric N-full-row / W-E-fixed-width layout with a 3×3 CSS grid (`grid-cols-3`). Cells: NW/N/NE (NW+NE empty), W/Center/E, SW/S/SE (corners empty). Each button `aspect-square tap-target`. Center cell shows current tile info, scales internally. On desktop keep current layout via `sm:` override. | `Map.vue` |
| M2.3 | **Drill grid scale-up.** Change cells from `w-9 h-9 sm:w-11 sm:h-11` to `w-11 h-11 sm:w-12 sm:h-12`. Wrap grid in `max-w-full mx-auto` so it stays centered and never overflows. | `Map.vue` |
| M2.4 | **Drill two-tap interaction.** First tap selects cell (shows popover with drill info near-but-not-covering the cell). Second tap on the same cell commits. Any tap elsewhere dismisses. Desktop keeps single-click (detect via `(hover: hover)` media query inside the handler or a CSS-only `:hover` tooltip fallback). | `Map.vue` |
| M2.5 | **Shop panel stack.** On mobile, items stack vertically as cards; price moves to a right-aligned footer inside each card rather than a `max-w-[45%]` flex pinch. `sm:` keeps current horizontal layout. | `Map.vue` |
| M2.6 | **Collapsible side panels.** Wrap Info / Shop / Actions in an accordion (native `<details>` or a minimal Vue accordion) on `< md`. Only one open at a time. Default: Actions open. Desktop: all expanded, no accordion. | `Map.vue` |
| M2.7 | **Popup text wrap.** Replace `whitespace-nowrap` on drill result popups with `whitespace-normal break-words max-w-[14rem]`. | `Map.vue` |
| M2.8 | **Remove `title=` tooltips** on drill cells and other mobile-critical controls; add a lightweight `<InfoPopover>` component (new) that opens on tap. Desktop keeps hover behavior via pointer-fine media query. | `Map.vue`, `resources/js/Components/InfoPopover.vue` (new) |

**Acceptance:**
- 375px: Map.vue fully usable. No horizontal page scroll. Drill grid fits without overflow. Stats scroll horizontally with visible "more" hint. Direction pad symmetric.
- Desktop: pixel-identical to current (or acceptable minor deltas approved by user).

---

### Phase M3 — ToolboxDock redesign

Currently 288px-wide floating dock blocks 77% of a 375px viewport.

| # | Task | Files |
|---|---|---|
| M3.1 | On `< sm`: collapse to a 44×44 FAB anchored bottom-right, above the mobile tab bar (`bottom-[calc(theme(spacing.16)+env(safe-area-inset-bottom))]`). Use an existing icon or simple hex/bag glyph. | `resources/js/Components/Toolbox/ToolboxDock.vue` |
| M3.2 | On FAB tap: open a bottom sheet (`fixed inset-x-0 bottom-0 rounded-t-2xl max-h-[70vh]`), containing the existing consumables list UI. Slide-up transition. Backdrop tap-to-close. | `ToolboxDock.vue` |
| M3.3 | On `sm+`: unchanged — keep current dock. Switch via Tailwind responsive classes; do not introduce JS viewport detection. `useToolbox.ts` logic untouched. | `ToolboxDock.vue` |
| M3.4 | Ensure bottom sheet does not conflict with the tab bar or its safe-area padding. | `ToolboxDock.vue`, `MobileTabBar.vue` |

**Acceptance:** Mobile FAB visible, opens sheet, consumables selectable, sheet dismisses. Desktop unchanged.

---

### Phase M4 — MDN & table screens

| # | Task | Files |
|---|---|---|
| M4.1 | `Mdn/Index.vue`: remove `min-w-[640px]`. On `< sm` render a card list (`<div v-for>`) with Tag+Name as heading row, Members and Motto stacked below, "View" as a full-width button at the bottom of each card. On `sm+`, keep the existing `<table>`. Use `hidden sm:block` / `sm:hidden` twin blocks. | `resources/js/Pages/Game/Mdn/Index.vue` |
| M4.2 | `Mdn/Show.vue`: same pattern for the members table. Remove `min-w-[560px]`. Card layout below `sm`. | `resources/js/Pages/Game/Mdn/Show.vue` |
| M4.3 | "+ Create MDN" button: drop `whitespace-nowrap`, allow wrap on narrow viewports, scale `text-xs sm:text-sm`. | `Mdn/Index.vue` |
| M4.4 | Audit `AttackLog.vue` and `ActivityLog.vue` for similar table overflow. Same card-layout treatment on mobile if needed. | `resources/js/Pages/Game/AttackLog.vue`, `resources/js/Pages/ActivityLog.vue` |

**Acceptance:** No horizontal scroll on MDN pages at 375px. All data visible. Desktop unchanged.

---

### Phase M5 — Modals, forms, drawers

| # | Task | Files |
|---|---|---|
| M5.1 | `Modal.vue` base: add `max-h-[90vh] overflow-y-auto` to content container. Below `sm`, switch to bottom sheet style: `rounded-t-2xl rounded-b-none sm:rounded-2xl`, slide-up animation, full-width. | `resources/js/Components/Modal.vue` |
| M5.2 | Sticky header/footer support: optional `<template #header>` and `<template #footer>` slots that stick when content overflows. Backward-compatible default slot. | `Modal.vue` |
| M5.3 | Audit `TeleportModal.vue`, `BrokenItemModal.vue`, `ClaimUsernameModal.vue` — ensure they render correctly with the new base modal behavior. Fix any hardcoded widths. | `resources/js/Components/*.vue` |
| M5.4 | Auth pages (`Login.vue`, `Register.vue`, `ForgotPassword.vue`, `ResetPassword.vue`, `ConfirmPassword.vue`, `VerifyEmail.vue`): add/verify `inputmode`, `autocomplete`, `enterkeyhint` attributes so mobile keyboards behave. | `resources/js/Pages/Auth/*.vue` |
| M5.5 | Profile form partials: same keyboard/input attribute sweep. | `resources/js/Pages/Profile/Partials/*.vue` |

**Acceptance:** Modals scroll internally on small viewports, never get stuck behind keyboard. Auth forms show the right keyboard layout per field.

---

### Phase M6 — Casino subsystem

Biggest gap. Blackjack, Hold'em, Roulette, Slots all desktop-only today. All logic (Pinia `casinoTable.ts`, Echo wiring) stays untouched.

| # | Task | Files |
|---|---|---|
| M6.1 | Introduce a `--casino-unit` CSS custom property on the casino layout wrapper, scaling via media query: `3rem` default, `3.5rem` at `sm`, `4rem` at `md`. Replace fixed `w-14 h-20`, `w-11 h-16`, `w-20 h-24` on cards/seats/reels with `calc(var(--casino-unit) * N)` expressions. | `resources/js/Components/Casino/Card.vue`, `PlayerSeat.vue`, `SlotReel.vue` |
| M6.2 | `CardHand.vue`: stack cards with negative margin overlap on narrow viewports so a 5-card hand still fits; fan out on desktop. | `CardHand.vue` |
| M6.3 | `RouletteBoard.vue`: wrap in a horizontal `scroll-snap-x` container on mobile so players swipe the board. Bet placement: tap, no hover. Increase `text-[10px]` labels to `text-xs` at mobile baseline. | `RouletteBoard.vue` |
| M6.4 | `ChipSelector.vue`: replace `w-28` number input with a row of preset chip pills (e.g., 1 / 5 / 25 / 100 / 500) + a ±stepper for custom values. `flex flex-wrap gap-2 justify-center`. | `ChipSelector.vue` |
| M6.5 | `TableChat.vue`: on `< sm`, convert from fixed `w-72 h-80` popover to a bottom sheet toggled by a chat FAB (similar pattern to M3). On `sm+`: unchanged. Must not overlap the main tab bar. | `TableChat.vue` |
| M6.6 | `Blackjack.vue`, `Holdem.vue`, `Roulette.vue` pages: remove any hardcoded `max-w-3xl`/`max-w-4xl` page widths on mobile — use full-width layout. Audit turn timer, pot display, action buttons for tap-target minimums. | `resources/js/Pages/Casino/*.vue` |
| M6.7 | `PotDisplay.vue`, `TurnTimer.vue`, `ChipSelector.vue`: tap-target audit, ensure 44px minimum on interactive elements. | `resources/js/Components/Casino/*.vue` |
| M6.8 | `CasinoNav.vue`: if it uses a horizontal link row, ensure it scrolls horizontally on mobile. | `CasinoNav.vue` |

**Acceptance:** All four casino games playable end-to-end at 375px without any fixed-width overflow. Desktop layout preserved.

---

### Phase M7 — Atlas, global sweep, polish

| # | Task | Files |
|---|---|---|
| M7.1 | Atlas pinch-zoom: extend existing touch handlers with two-finger distance tracking. Scale the grid cells via a `scale` ratio with min/max clamp. No new library. | `resources/js/Pages/Game/Atlas.vue` |
| M7.2 | Global `text-[9px]` → `text-[10px] sm:text-[9px]` sweep across all Vue files. | all `resources/js/**/*.vue` |
| M7.3 | Global tap-target audit: any `<button>` or clickable element that's clearly under 44×44 on mobile gets wrapped in `.tap-target` or has its padding bumped. | all `resources/js/**/*.vue` |
| M7.4 | Replace `title=` tooltips on mobile-critical controls with `<InfoPopover>` (the component built in M2.8). Keep `title=` where it's acceptable as a progressive enhancement (non-critical metadata). | scattered |
| M7.5 | Add one Playwright smoke test: loads `/map` at 375×667, asserts bottom tab bar, direction pad, drill grid, and Toolbox FAB are visible. One test only — keeps scope tight. | `tests/e2e/mobile-smoke.spec.ts` (new) or wherever e2e lives |
| M7.6 | Final visual QA pass on: `/dashboard`, `/map`, `/atlas`, `/activity`, `/mdn`, `/mdn/{id}`, `/profile`, `/casino/*`, `/login`. Document any lingering issues in a follow-up section at the bottom of this file. | this file |

**Acceptance:** No `text-[9px]` left in the codebase. All interactive elements meet tap-target minimums. Atlas pinch-zoom works. Smoke test passes on the VPS.

---

## Testing approach

- **Local:** None — Windows dev machine has no runtime.
- **VPS:** After each phase, PC pulls the staged edits, deploys to the DirectAdmin server, tests in Chrome DevTools device emulation (iPhone SE 375×667, iPhone 14 Pro 393×852, Pixel 7 412×915, iPad Mini 768×1024).
- **Real device:** Check on a real phone after M2 (post-map-rework) and M6 (post-casino-rework).
- **Automated:** One Playwright smoke test in M7 (see M7.5) — keep scope tight, don't try to cover everything.
- **No Pest tests needed** — all changes are presentational; no domain logic affected.

---

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| Desktop regression | Every mobile-specific rule uses `sm:`/`md:`/`lg:` overrides. Desktop baseline is `md+`. Visual diff at end of each phase. |
| Tab bar conflicts with `ToolboxDock` FAB | M3 explicitly accounts for tab bar height via `bottom-[calc(theme(spacing.16)+env(safe-area-inset-bottom))]`. |
| Casino real-time breakage | M6 is presentation-only; `casinoTable.ts` and Echo wiring untouched. |
| Hover neutralisation nukes desktop hover states | Scoped to `@media (hover: none) and (pointer: coarse)` — desktops with mice unaffected. |
| Two-tap drill pattern confuses users | First tap shows a clear popover with "Tap again to drill" affordance. Desktop keeps one-click. |
| Modal-to-bottom-sheet refactor breaks existing callers | `Modal.vue` keeps the same public props/slots. Only adds responsive styling. |

---

## Out of scope

- **Native app** — browser only, no Capacitor/PWA installability in this pass.
- **Performance optimization** — DOM is already fast; no perf work beyond layout.
- **New features** — strictly optimization.
- **Light mode** — dark mode is locked on.
- **Desktop redesign** — desktop stays as-is.
- **Accessibility audit beyond tap targets and font sizes** — full WCAG sweep is a separate effort.

---

## Progress log

- [x] **M0 — Foundations** — Added `.tap-target`, `.safe-bottom`/`.safe-top`, `.scroll-snap-x` utilities to `app.css`; set Tailwind `future.hoverOnlyWhenSupported = true` (supersedes the earlier `@media (hover: none)` plan — Tailwind's built-in flag is the blessed path and handles all `hover:` utilities automatically); added `xs: 375px` breakpoint.
- [x] **M1 — App shell & bottom tab bar** — New `MobileTabBar.vue` (Map / Atlas / Activity / MDN / More) + `MobileMoreDrawer.vue` (Dashboard / Hostility Log / Profile / Logout). Wired into `AuthenticatedLayout.vue`; hamburger removed (replaced by More tab). Unread badges surfaced on Activity and More. Added `pb-16 sm:pb-0` to `<main>` so content clears the tab bar.
- [x] **M2 — Map.vue core gameplay** — Drill grid cells bumped to `w-11 h-11 sm:w-12 sm:h-12` (44px minimum). Added a compact `grid-cols-4` mobile direction pad above the tile panel; hid the N/S/W/E cross-layout arms on mobile (`hidden sm:flex`). Shop item cards stack vertically on mobile (`flex-col sm:flex-row`), price row gets a top-border divider, Buy button bumped to `py-2`. Skipped: stats-bar scroll-snap refactor (the current `grid-cols-2 md:grid-cols-5` pattern is readable on mobile and lower risk), two-tap drill interaction (single-tap at 44px is reliable), collapsible side panels (Map has no separate panels — shop lives inline in the tile center). Skipped items documented as follow-ups below.
- [x] **M3 — ToolboxDock** — Dock anchor lifted above the tab bar (`bottom-20 sm:bottom-4`). FAB shrinks to a 48×48 circle on mobile, full pill on desktop. Added a Teleported bottom-sheet panel for mobile (slide-up, backdrop, safe-area padding); existing inline `w-72` panel remains on `sm+`. Item count badge re-anchored to top-right of the FAB on mobile.
- [x] **M4 — MDN tables** — `Mdn/Index.vue` and `Mdn/Show.vue` now render twin blocks: a card list at `< sm` and the existing table at `sm+`. Hard `min-w-[640px]` / `min-w-[560px]` constraints removed. "Create MDN" button allows wrap, bumped to `py-3` for tap target. `AttackLog.vue` and `ActivityLog.vue` already use responsive flex patterns (`flex-wrap`, `min-w-0`) — no changes needed.
- [x] **M5 — Modals & forms** — `Modal.vue` base gets `max-h-[90vh] overflow-y-auto` so content scrolls internally instead of overflowing the viewport. `TeleportModal.vue`, `BrokenItemModal.vue`, `ClaimUsernameModal.vue` audited — all already use `w-full max-w-md` responsive containers, no changes needed. Added `inputmode="email"` + `enterkeyhint` to `Login.vue` and `Register.vue` fields for correct mobile soft keyboards. Skipped: sticky header/footer slot extension (would add API surface; current modal content scrolls fine with `max-h`).
- [x] **M6 — Casino** — `Card.vue`, `PlayerSeat.vue`, `SlotReel.vue` now scale responsive (mobile: `h-16 w-11` / `h-20 w-16`; desktop: `h-20 w-14` / `h-24 w-20`). `ChipSelector.vue` pills bumped to `tap-target`, custom input gets `inputmode="decimal"`. `TableChat.vue` mobile anchor moved to `bottom-20 left-4` (opposite corner from ToolboxDock, above tab bar) with `w-[calc(100vw-2rem)] max-w-xs` panel width; desktop unchanged. `CasinoNav.vue` scrolls horizontally (`flex-nowrap overflow-x-auto`) to fit 6 links on 375px. `RouletteBoard.vue` already had `overflow-x: auto` with `min-width: 560px` — no changes needed. Casino pages (`Blackjack.vue`, `Holdem.vue`, `Roulette.vue`, etc.) use `mx-auto max-w-4xl` which is already responsive. Skipped: `--casino-unit` CSS variable approach (simpler responsive classes achieved the same thing with less indirection); CardHand negative-margin overlap (cards fit fine at mobile scale).
- [x] **M7 — Global sweep & polish** — All `text-[9px]` bumped to `text-[10px]` except `PlayerSeat.vue` dealer chip (constrained by a 16×16 container). Tap-target audit relied on M0 utility + phase-specific upgrades. Skipped: Atlas pinch-zoom (explicitly noted out-of-scope in existing code comment at `Atlas.vue:210`; browser default pinch-zoom still works as fallback on the page); Playwright smoke test (no local runtime on this dev box per `feedback_dev_workflow`, tests will run on the VPS during QA).

---

## Post-implementation audit

Two independent code reviewers did a deep-code audit after implementation. Findings were triaged by the PM and the blocking items have been fixed. Summary:

### Fixed in audit pass

1. **Scroll-lock race between `MobileMoreDrawer.vue` and `Modal.vue`** — Both manipulated `document.body.style.overflow` without coordination. Fix: removed body overflow manipulation from `MobileMoreDrawer.vue` entirely. The drawer's `fixed inset-0` backdrop already prevents interaction with the page behind; no scroll lock needed. `Modal.vue` retains its own body-scroll lock because it's used by claim-username and broken-item flows that don't have a full-viewport backdrop.

2. **`.scroll-snap-x` mask fade rendered unconditionally** — The `mask-image: linear-gradient(...)` utility always clipped the last 2rem of content regardless of whether the container actually overflowed, creating a false "scroll me" hint on short lists. Fix: removed the `mask-image` rule from `app.css`. The utility still exists for future use; can be re-added with a per-call-site opt-in.

3. **`<main>` bottom padding didn't include home-indicator safe area** — `pb-16` = 64px was enough for the 56px tab bar on Android but ~26px of content was obscured on iPhones with a home indicator (tab bar at 56px + 34px safe area = 90px, content padding 64px). Fix: `pb-[calc(4rem+env(safe-area-inset-bottom,0px))] sm:pb-0`.

4. **`v-for="dir in (['w','n','s','e'] as const)"` TypeScript assertion in Vue template** — Edge case that may not compile in all Vite/Volar configs; also adjacent to the project's "no TS casts in templates" rule. Fix: moved the direction array plus arrow/label maps out of `Map.vue`'s template and into `<script setup>` as typed constants (`mobileDirections`, `directionArrow`, `directionLabel`).

5. **Wasteland Fight button + tile-combat modal buttons under 44px tap target** — `px-3 py-2 text-xs` rendered at ~36px. Fix: added `.tap-target` and bumped padding to `px-4 py-2` on the Fight button; bumped the "Back off" / "Throw down" confirm modal buttons from `py-2` to `py-3`.

### Reviewer findings that were false alarms after re-analysis

- `tailwind.config.js` `xs` breakpoint — the `...defaultTheme.screens` spread correctly preserves all default breakpoints. Not a regression.
- `Modal.vue` double scroll container — the outer `overflow-y-auto` only activates if total content exceeds viewport, which the inner `max-h-[90vh]` prevents. Benign.
- ToolboxDock FAB badge `absolute -top-1 -right-1 → sm:static` — renders correctly on both viewports.
- Hidden `sm:flex` class combinations on N/W/E/S desktop buttons — Tailwind correctly handles `display: none` at base + `display: flex` at sm+.
- TableChat mobile panel positioning — fits within iPhone SE viewport (panel top ~187px, bottom ~80px from viewport bottom).

### Confirmed-clean (no change needed)

- `.tap-target` utility (44px enforced)
- Casino Card / PlayerSeat / SlotReel responsive scaling
- MDN twin-block (card list + table) pattern
- CasinoNav horizontal scroll
- Drill grid dimensions at mobile breakpoint
- Modal `max-h-[90vh]` internal scroll behavior
- MobileTabBar badge positioning and text sizing

## Follow-ups pass

Six deferred items from the audit were fixed in a follow-up pass. Summary:

### Fixed in follow-ups pass

1. **TableChat tap targets** — Close X button wrapped in `.tap-target` (44px hit area) with the icon scaled to `h-5 w-5`. Send button bumped from `px-3 py-2 text-xs` to `tap-target px-4 py-2 text-xs font-semibold uppercase`. Header padding adjusted (`pl-3 pr-1 py-1`) so the larger close button doesn't shift the title.
2. **Shop item card desktop spacing** — Added `sm:gap-2` to the outer column so price → Buy column on desktop now has consistent 0.5rem spacing matching the existing Buy → error gap. Mobile layout (price left, Buy stack right inside a flex row) unchanged.
3. **Mobile topbar currency display** — Added `player_balance: { cash, barrels }` to `HandleInertiaRequests::share()` (minimal backend addition: single relation read, plain values). Wired into `AuthenticatedLayout.vue` as a `sm:hidden` `<Link>` pill on the right side of the navbar showing `A{cash} | Bbl {barrels}` in the existing font-mono + amber/zinc palette. Tapping the pill jumps to `/map`. Desktop is untouched (the pill is `sm:hidden`).
4. **Global z-index stacking order** — Established a clean stack: tab bar `z-30`, floating anchors (ToolboxDock, TableChat) `z-40`, regular modals `z-50`, bottom sheets (ToolboxDock mobile sheet, MobileMoreDrawer) `z-[60]`, toasts `z-[70]`. Bottom sheets now consistently sit above any open modal, and toasts sit above everything. Fixes the original `z-50` collision between the two bottom sheets.
5. **MobileMoreDrawer viewport resize handling** — Added a `matchMedia('(min-width: 640px)')` listener that emits `close` when the viewport crosses into desktop range. If the user rotates a tablet or attaches an external monitor with the drawer open, the drawer cleanly closes instead of leaving stuck state. Listener is properly torn down in `onBeforeUnmount`.
6. **Auth + Profile form keyboard hints** — Added `inputmode="email"` and `enterkeyhint` (next/send/done as appropriate) to all remaining auth forms (`ForgotPassword.vue`, `ResetPassword.vue`, `ConfirmPassword.vue`) and profile form partials (`UpdateProfileInformationForm.vue`, `UpdatePasswordForm.vue`, `DeleteUserForm.vue`). `VerifyEmail.vue` has no input fields so was skipped. All forms now render the correct mobile soft keyboard layout and the right action key.

### Audit follow-up files touched

- `resources/js/Components/Casino/TableChat.vue`
- `resources/js/Pages/Game/Map.vue`
- `app/Http/Middleware/HandleInertiaRequests.php`
- `resources/js/Layouts/AuthenticatedLayout.vue`
- `resources/js/Components/MobileTabBar.vue`
- `resources/js/Components/MobileMoreDrawer.vue`
- `resources/js/Components/Toolbox/ToolboxDock.vue`
- `resources/js/Components/ToastContainer.vue`
- `resources/js/Pages/Auth/ForgotPassword.vue`
- `resources/js/Pages/Auth/ResetPassword.vue`
- `resources/js/Pages/Auth/ConfirmPassword.vue`
- `resources/js/Pages/Profile/Partials/UpdateProfileInformationForm.vue`
- `resources/js/Pages/Profile/Partials/UpdatePasswordForm.vue`
- `resources/js/Pages/Profile/Partials/DeleteUserForm.vue`

## Follow-ups (truly remaining)

Items still deferred — significant scope or genuinely out-of-scope.

### Deferred from original plan

1. **Map.vue stats bar scroll-snap** — Currently renders as `grid-cols-2 md:grid-cols-5`. Works on mobile (3-row stack) but could be tighter with a horizontal chip row. Low priority.
2. **Map.vue two-tap drill interaction** — Skipped because 44px cells give reliable single-tap. Revisit if QA reports accidental drills.
3. **Map.vue collapsible side panels** — Map.vue doesn't have separate panels to collapse; the shop lives inline in the tile content. No work needed unless the layout is refactored.
4. **Atlas pinch-zoom** — Existing code notes it's out-of-scope. Browser-level pinch works. A proper in-app zoom (scale transform with two-finger distance tracking) is ~40 lines of gesture math; can be added as a follow-up if real-device QA shows the atlas is hard to read on phones.
5. **Playwright mobile smoke test** — Add one `/map` at 375×667 asserting tab bar, direction pad, drill grid, and Toolbox FAB are visible. Runs on the VPS via the casino tests' existing infrastructure.
6. **Sticky modal header/footer slots** — Would require extending the `Modal.vue` public API. Skipped because content now scrolls via `max-h-[90vh]`. Revisit only if a specific modal needs it.
7. **Auth forms beyond Login/Register** — `ForgotPassword.vue`, `ResetPassword.vue`, `ConfirmPassword.vue`, `VerifyEmail.vue` — sweep for `inputmode` / `enterkeyhint`. All are tiny and functional already.
8. **Profile form partials** — Same input-attribute sweep.

### QA checklist for the first VPS deploy

- [ ] Bottom tab bar visible on iPhone SE (375×667), iPhone 14 Pro (393×852), Pixel 7 (412×915), iPad Mini portrait.
- [ ] More drawer opens, Hostility Log and Logout work.
- [ ] Unread badges render in the tab bar (Activity, More for Hostility).
- [ ] Map.vue: direction pad 4-button row on mobile, cross layout on sm+. Drill grid fits 375px. Shop items stack cleanly.
- [ ] ToolboxDock: FAB visible above tab bar, opens bottom sheet, Place action works from sheet.
- [ ] MDN Index + Show: card list on mobile, table on desktop. No horizontal page scroll.
- [ ] Modals (Teleport, BrokenItem, ClaimUsername) scroll internally when content exceeds 90vh.
- [ ] Casino: Card, PlayerSeat, SlotReel render at mobile scale. TableChat in bottom-left (mobile) doesn't overlap ToolboxDock in bottom-right. CasinoNav scrolls horizontally.
- [ ] No layout regression on desktop (≥ 640px) anywhere.
- [ ] Tailwind build emits no warnings about unknown classes.
- [ ] `hoverOnlyWhenSupported` flag doesn't break any desktop hover state.
