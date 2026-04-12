<script setup lang="ts">
import { computed } from 'vue';

/**
 * American-style roulette table layout.
 *
 * Internal number convention:
 *   0   — single zero (green)
 *   37  — double zero (green, rendered as "00")
 *   1..36 — standard pockets
 *
 * 37 = 00 is chosen so the existing int column in casino_rounds stays
 * unchanged. The server side uses the same convention in
 * RouletteService::DOUBLE_ZERO.
 *
 * Layout follows a real American double-zero felt:
 *
 *   +----+----+----+ ... +----+-----+
 *   | 0  | 3  | 6  | ... | 36 |     |
 *   +----+----+----+ ... +----+ 2:1 |
 *   |    | 2  | 5  | ... | 35 | 2:1 |
 *   | 00 +----+----+ ... +----+ 2:1 |
 *   |    | 1  | 4  | ... | 34 |     |
 *   +----+----+----+ ... +----+-----+
 *        | 1st 12  | 2nd 12  | 3rd 12 |
 *        +---+-----+----+----+---+---+
 *        |1-18|EVEN| RED|BLK |ODD|19-36|
 *
 * Chip overlays for placed bets are rendered absolutely on top of the
 * grid — one div per bet, positioned by bet_type + numbers[0].
 */

const DOUBLE_ZERO = 37;
const RED_NUMBERS = new Set([1,3,5,7,9,12,14,16,18,19,21,23,25,27,30,32,34,36]);

export interface PlacedBet {
    id: string;
    bet_type: string;
    numbers: number[];
    amount: number;
    mine: boolean;
}

const props = defineProps<{
    variant: 'american' | 'european';
    /** Combined server + broadcast chip list for the current round. */
    chips: PlacedBet[];
    /** Min/max bet for client-side guard rendering only. */
    minBet: number;
    maxBet: number;
    /** Formatter for chip labels (e.g. "A0.25" or "25 bbl"). */
    formatAmount: (v: number) => string;
}>();

const emit = defineEmits<{
    bet: [betType: string, numbers: number[]];
}>();

// The visual grid is columns-first — 12 columns of 3 numbers each,
// top row 3/6/9..., bottom row 1/4/7...
const columns = computed(() => {
    const cols: number[][] = [];
    for (let col = 0; col < 12; col++) {
        const base = col * 3;
        cols.push([base + 3, base + 2, base + 1]); // top, middle, bottom
    }
    return cols;
});

function isRed(n: number): boolean {
    return RED_NUMBERS.has(n);
}

function numberCellClass(n: number): string {
    if (n === 0 || n === DOUBLE_ZERO) {
        return 'bg-emerald-700 hover:bg-emerald-600';
    }
    if (isRed(n)) {
        return 'bg-red-700 hover:bg-red-600';
    }
    return 'bg-zinc-900 hover:bg-zinc-800';
}

function displayNumber(n: number): string {
    if (n === DOUBLE_ZERO) return '00';
    return String(n);
}

function onStraight(n: number) {
    emit('bet', 'straight', [n]);
}

function onColumn(col: 1 | 2 | 3) {
    emit('bet', `column_${col}`, []);
}

function onDozen(dz: 1 | 2 | 3) {
    emit('bet', `dozen_${dz}`, []);
}

function onOutside(type: 'red' | 'black' | 'odd' | 'even' | 'low' | 'high') {
    emit('bet', type, []);
}

function onTopLine() {
    // top_line = 0, 00, 1, 2, 3. Server ignores the numbers array for
    // this bet type and hardcodes the set, but we send it anyway so the
    // broadcast event can position the chip accurately for everyone.
    emit('bet', 'top_line', [0, DOUBLE_ZERO, 1, 2, 3]);
}

// ---- Chip positioning ---------------------------------------------------
//
// Each placed bet renders as a chip absolutely positioned over the region
// it covers. Positions are expressed as CSS grid cell coordinates into
// the same grid the board uses, so chips scale with the layout.
//
// Straight bets: chip sits on the single cell.
// Outside bets (red/black/odd/even/low/high): chip sits on that bar.
// Column / dozen: chip sits on its anchor area.
// top_line: chip straddles the 0/00 and 1/2/3 corner — rendered as a
//    special marker anchored to the 0 cell.
// Split/street/corner/line: rendered on the first number for now
//    (click-to-place for these is a follow-up; server already accepts them).

interface ChipPosition {
    area: string; // CSS grid-area string
    color: string; // chip color (own vs others)
    label: string;
}

function chipColor(chip: PlacedBet): string {
    return chip.mine
        ? 'bg-amber-400 border-amber-200 text-zinc-950'
        : 'bg-sky-500 border-sky-300 text-zinc-950';
}

/**
 * Resolve a bet to a CSS grid-area rectangle. The board grid below has
 * 14 columns: col 1 = the 0/00 block, cols 2..13 = the 12 number columns,
 * col 14 = the 2-to-1 spur. Rows 1..3 are the number grid; row 4 is the
 * dozens row; row 5 is the outside bets row.
 */
function chipArea(chip: PlacedBet): string {
    const t = chip.bet_type;

    if (t === 'straight') {
        const n = chip.numbers[0];
        if (n === 0) return '1 / 1 / 2 / 2';           // top half of the 0/00 block
        if (n === DOUBLE_ZERO) return '3 / 1 / 4 / 2'; // bottom half of the 0/00 block
        // n is 1..36 — map to row/col
        const col = Math.ceil(n / 3) + 1; // col 2..13
        const mod = ((n - 1) % 3);        // 0 = bottom (row 3), 1 = middle (row 2), 2 = top (row 1)
        const row = 3 - mod;
        return `${row} / ${col} / ${row + 1} / ${col + 1}`;
    }

    if (t === 'column_1') return '3 / 14 / 4 / 15';
    if (t === 'column_2') return '2 / 14 / 3 / 15';
    if (t === 'column_3') return '1 / 14 / 2 / 15';

    if (t === 'dozen_1') return '4 / 2 / 5 / 6';
    if (t === 'dozen_2') return '4 / 6 / 5 / 10';
    if (t === 'dozen_3') return '4 / 10 / 5 / 14';

    if (t === 'low')   return '5 / 2 / 6 / 4';
    if (t === 'even')  return '5 / 4 / 6 / 6';
    if (t === 'red')   return '5 / 6 / 6 / 8';
    if (t === 'black') return '5 / 8 / 6 / 10';
    if (t === 'odd')   return '5 / 10 / 6 / 12';
    if (t === 'high')  return '5 / 12 / 6 / 14';

    if (t === 'top_line') return '2 / 1 / 3 / 2'; // middle of the 0/00 block

    // Fallback for split/street/corner/line: pin to the first number.
    // These don't have click-to-place yet but the data is valid.
    if (chip.numbers.length > 0) {
        return chipArea({ ...chip, bet_type: 'straight', numbers: [chip.numbers[0]] });
    }

    return '1 / 1 / 2 / 2';
}

const positionedChips = computed<Array<{ chip: PlacedBet; area: string }>>(() => {
    return props.chips.map((chip) => ({ chip, area: chipArea(chip) }));
});
</script>

<template>
    <div class="roulette-board-wrap">
        <!-- Felt background + inner grid -->
        <div class="roulette-board relative rounded-lg border-2 border-amber-900/60 bg-gradient-to-b from-emerald-900 to-emerald-950 p-3 shadow-[inset_0_0_40px_rgba(0,0,0,0.5)]">

            <div class="roulette-grid relative">
                <!-- 0 / 00 block (col 1). On American tables col 1 is split
                     into '0' (top), a '5-line' anchor (middle), and '00'
                     (bottom). On European tables '0' spans the whole column
                     because there's no 00 pocket and no top-line bet. -->
                <button
                    type="button"
                    :class="[numberCellClass(0), 'rouletteCell rounded-l']"
                    :style="variant === 'american'
                        ? 'grid-area: 1 / 1 / 2 / 2'
                        : 'grid-area: 1 / 1 / 4 / 2'"
                    @click="onStraight(0)"
                    title="Straight on 0 — pays 35:1"
                >
                    0
                </button>
                <!-- top_line bet anchor — middle of the 0/00 block -->
                <button
                    v-if="variant === 'american'"
                    type="button"
                    class="rouletteCell bg-emerald-800 hover:bg-emerald-700 text-[10px] uppercase tracking-widest"
                    style="grid-area: 2 / 1 / 3 / 2"
                    @click="onTopLine"
                    title="Top line (0/00/1/2/3) — pays 6:1"
                >
                    5-line
                </button>
                <button
                    v-if="variant === 'american'"
                    type="button"
                    :class="[numberCellClass(DOUBLE_ZERO), 'rouletteCell rounded-l']"
                    style="grid-area: 3 / 1 / 4 / 2"
                    @click="onStraight(DOUBLE_ZERO)"
                    title="Straight on 00 — pays 35:1"
                >
                    00
                </button>

                <!-- Number grid: 12 columns × 3 rows -->
                <template v-for="(col, ci) in columns" :key="`col-${ci}`">
                    <button
                        v-for="(n, ri) in col"
                        :key="n"
                        type="button"
                        :class="[numberCellClass(n), 'rouletteCell', 'font-bold']"
                        :style="`grid-area: ${ri + 1} / ${ci + 2} / ${ri + 2} / ${ci + 3}`"
                        @click="onStraight(n)"
                        :title="`Straight on ${n} — pays 35:1`"
                    >
                        {{ displayNumber(n) }}
                    </button>
                </template>

                <!-- 2 to 1 column bets (col 14, rows 1–3) -->
                <button
                    type="button"
                    class="rouletteCell bg-emerald-800 hover:bg-emerald-700 text-[11px] font-semibold tracking-wider"
                    style="grid-area: 1 / 14 / 2 / 15"
                    @click="onColumn(3)"
                    title="Top column (3, 6, 9, ..., 36) — pays 2:1"
                >
                    2 to 1
                </button>
                <button
                    type="button"
                    class="rouletteCell bg-emerald-800 hover:bg-emerald-700 text-[11px] font-semibold tracking-wider"
                    style="grid-area: 2 / 14 / 3 / 15"
                    @click="onColumn(2)"
                    title="Middle column (2, 5, 8, ..., 35) — pays 2:1"
                >
                    2 to 1
                </button>
                <button
                    type="button"
                    class="rouletteCell bg-emerald-800 hover:bg-emerald-700 text-[11px] font-semibold tracking-wider"
                    style="grid-area: 3 / 14 / 4 / 15"
                    @click="onColumn(1)"
                    title="Bottom column (1, 4, 7, ..., 34) — pays 2:1"
                >
                    2 to 1
                </button>

                <!-- Dozens (row 4) -->
                <button
                    type="button"
                    class="rouletteCell bg-emerald-800 hover:bg-emerald-700 text-xs font-bold uppercase tracking-wider"
                    style="grid-area: 4 / 2 / 5 / 6"
                    @click="onDozen(1)"
                    title="1st 12 (1–12) — pays 2:1"
                >
                    1st 12
                </button>
                <button
                    type="button"
                    class="rouletteCell bg-emerald-800 hover:bg-emerald-700 text-xs font-bold uppercase tracking-wider"
                    style="grid-area: 4 / 6 / 5 / 10"
                    @click="onDozen(2)"
                    title="2nd 12 (13–24) — pays 2:1"
                >
                    2nd 12
                </button>
                <button
                    type="button"
                    class="rouletteCell bg-emerald-800 hover:bg-emerald-700 text-xs font-bold uppercase tracking-wider"
                    style="grid-area: 4 / 10 / 5 / 14"
                    @click="onDozen(3)"
                    title="3rd 12 (25–36) — pays 2:1"
                >
                    3rd 12
                </button>

                <!-- Outside bets (row 5) -->
                <button
                    type="button"
                    class="rouletteCell bg-emerald-800 hover:bg-emerald-700 text-xs font-bold tracking-widest"
                    style="grid-area: 5 / 2 / 6 / 4"
                    @click="onOutside('low')"
                    title="1–18 — pays 1:1"
                >
                    1–18
                </button>
                <button
                    type="button"
                    class="rouletteCell bg-emerald-800 hover:bg-emerald-700 text-xs font-bold uppercase tracking-widest"
                    style="grid-area: 5 / 4 / 6 / 6"
                    @click="onOutside('even')"
                    title="Even — pays 1:1"
                >
                    Even
                </button>
                <button
                    type="button"
                    class="rouletteCell bg-red-700 hover:bg-red-600 text-xs font-bold uppercase tracking-widest"
                    style="grid-area: 5 / 6 / 6 / 8"
                    @click="onOutside('red')"
                    title="Red — pays 1:1"
                >
                    ♦ Red
                </button>
                <button
                    type="button"
                    class="rouletteCell bg-zinc-900 hover:bg-zinc-800 text-xs font-bold uppercase tracking-widest text-white"
                    style="grid-area: 5 / 8 / 6 / 10"
                    @click="onOutside('black')"
                    title="Black — pays 1:1"
                >
                    ♠ Black
                </button>
                <button
                    type="button"
                    class="rouletteCell bg-emerald-800 hover:bg-emerald-700 text-xs font-bold uppercase tracking-widest"
                    style="grid-area: 5 / 10 / 6 / 12"
                    @click="onOutside('odd')"
                    title="Odd — pays 1:1"
                >
                    Odd
                </button>
                <button
                    type="button"
                    class="rouletteCell bg-emerald-800 hover:bg-emerald-700 text-xs font-bold tracking-widest"
                    style="grid-area: 5 / 12 / 6 / 14"
                    @click="onOutside('high')"
                    title="19–36 — pays 1:1"
                >
                    19–36
                </button>

                <!-- Chip overlay — absolute-positioned over the same grid cells.
                     We render them as pointer-events:none so the underlying
                     cell button still catches clicks for additional bets. -->
                <div
                    v-for="(entry, idx) in positionedChips"
                    :key="entry.chip.id + '-' + idx"
                    class="chip-overlay pointer-events-none"
                    :style="`grid-area: ${entry.area}`"
                >
                    <span
                        class="chip"
                        :class="chipColor(entry.chip)"
                        :title="`${entry.chip.mine ? 'Your' : 'Player'} ${entry.chip.bet_type} — ${formatAmount(entry.chip.amount)}`"
                    >
                        {{ formatAmount(entry.chip.amount) }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.roulette-board-wrap {
    /* Horizontal scroll on mobile — the board has a fixed min width so
       12 columns + the 0/00 spur + the 2-to-1 tab don't squash. */
    overflow-x: auto;
}

.roulette-grid {
    display: grid;
    /* col 1 = 0/00 block (slightly wider), cols 2..13 = number grid,
       col 14 = 2-to-1 tab (narrow). */
    grid-template-columns: 2.4rem repeat(12, minmax(2.25rem, 1fr)) 2.2rem;
    /* Rows 1–3 for the number grid, row 4 dozens, row 5 outside bets. */
    grid-template-rows: repeat(3, 2.75rem) 2.25rem 2.25rem;
    gap: 2px;
    min-width: 560px;
}

.rouletteCell {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    border: 1px solid rgba(0, 0, 0, 0.35);
    transition: background-color 120ms, transform 120ms;
    cursor: pointer;
    font-size: 0.85rem;
}

.rouletteCell:hover {
    transform: scale(1.03);
    z-index: 5;
}

.chip-overlay {
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10;
}

.chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 1.9rem;
    height: 1.9rem;
    padding: 0 0.35rem;
    border-radius: 9999px;
    border-width: 2px;
    border-style: dashed;
    font-size: 0.62rem;
    font-weight: 700;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.5);
    white-space: nowrap;
}
</style>
