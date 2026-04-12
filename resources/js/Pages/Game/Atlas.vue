<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TileIcon from '@/Components/TileIcon.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

interface AtlasTile {
    id: number;
    x: number;
    y: number;
    type: string;
    subtype: string | null;
    discovered_at: string;
}

interface Bounds {
    min_x: number;
    max_x: number;
    min_y: number;
    max_y: number;
}

const props = defineProps<{
    owns_atlas: boolean;
    tiles: AtlasTile[];
    current_tile_id: number;
    base_tile_id: number;
    bounds: Bounds | null;
}>();

function tileColor(type: string): string {
    return {
        base: 'text-emerald-400',
        oil_field: 'text-amber-400',
        post: 'text-sky-400',
        landmark: 'text-violet-400',
        auction: 'text-rose-400',
        ruin: 'text-zinc-500',
        wasteland: 'text-zinc-600',
    }[type] ?? 'text-zinc-500';
}

// Fast O(1) lookup of a discovered tile by "x:y" string.
const tileByCoord = computed<Record<string, AtlasTile>>(() => {
    const map: Record<string, AtlasTile> = {};
    for (const t of props.tiles) {
        map[`${t.x}:${t.y}`] = t;
    }
    return map;
});

// Build an ordered list of rows for rendering. Y is flipped so
// positive Y (north) is visually on top.
const rows = computed<number[]>(() => {
    if (!props.bounds) return [];
    const out: number[] = [];
    for (let y = props.bounds.max_y; y >= props.bounds.min_y; y--) {
        out.push(y);
    }
    return out;
});

const cols = computed<number[]>(() => {
    if (!props.bounds) return [];
    const out: number[] = [];
    for (let x = props.bounds.min_x; x <= props.bounds.max_x; x++) {
        out.push(x);
    }
    return out;
});

const width = computed(() => cols.value.length);
const height = computed(() => rows.value.length);

function isCurrent(t: AtlasTile | undefined): boolean {
    return t !== undefined && t.id === props.current_tile_id;
}

function isBase(t: AtlasTile | undefined): boolean {
    return t !== undefined && t.id === props.base_tile_id;
}

// ---- Click-and-drag panning --------------------------------------------
//
// Native horizontal scrollbars are hard to find on a trackpad, and the
// grid is tall enough on tablet that users had to blind-guess where they
// were on the map. This turns the scroll container into a standard
// "grab and pan" surface: hold mouse button, drag, scroll. Also wires
// up touch events so mobile players can swipe the map around.
//
// The container still scrolls via the wheel and via the native scrollbar
// — the drag handlers are additive and don't preventDefault on anything
// that would break those code paths.

const scrollContainer = ref<HTMLElement | null>(null);
const isDragging = ref(false);

// Captured at drag-start so we can compute the scroll delta in mousemove
// without worrying about pointer-capture or getBoundingClientRect jitter.
let dragStartX = 0;
let dragStartY = 0;
let dragStartScrollLeft = 0;
let dragStartScrollTop = 0;

function onPointerDown(e: MouseEvent) {
    // Ignore anything other than the primary mouse button so right-click
    // menus and middle-click scroll still work.
    if (e.button !== 0) return;
    const el = scrollContainer.value;
    if (!el) return;

    isDragging.value = true;
    dragStartX = e.clientX;
    dragStartY = e.clientY;
    dragStartScrollLeft = el.scrollLeft;
    dragStartScrollTop = el.scrollTop;
}

function onPointerMove(e: MouseEvent) {
    if (!isDragging.value) return;
    const el = scrollContainer.value;
    if (!el) return;

    // preventDefault here stops the browser from starting a native text
    // selection while the user drags across empty grid cells.
    e.preventDefault();

    const dx = e.clientX - dragStartX;
    const dy = e.clientY - dragStartY;
    el.scrollLeft = dragStartScrollLeft - dx;
    el.scrollTop = dragStartScrollTop - dy;
}

function onPointerUp() {
    isDragging.value = false;
}

// Touch variants. We track only the first touch point (single-finger
// pan); pinch-zoom is out of scope for this pass.
function onTouchStart(e: TouchEvent) {
    if (e.touches.length !== 1) return;
    const el = scrollContainer.value;
    if (!el) return;

    const t = e.touches[0];
    isDragging.value = true;
    dragStartX = t.clientX;
    dragStartY = t.clientY;
    dragStartScrollLeft = el.scrollLeft;
    dragStartScrollTop = el.scrollTop;
}

function onTouchMove(e: TouchEvent) {
    if (!isDragging.value) return;
    const el = scrollContainer.value;
    if (!el || e.touches.length !== 1) return;

    const t = e.touches[0];
    const dx = t.clientX - dragStartX;
    const dy = t.clientY - dragStartY;
    el.scrollLeft = dragStartScrollLeft - dx;
    el.scrollTop = dragStartScrollTop - dy;
}

function onTouchEnd() {
    isDragging.value = false;
}

// Window-level mouseup listener so releasing outside the scroll box
// (e.g. dragging off the top of the page) still ends the drag. Without
// this the cursor stays stuck in the grabbing state.
function onWindowMouseUp() {
    isDragging.value = false;
}

// ---- Auto-center on current tile ---------------------------------------
//
// The primary complaint wasn't just "there's no drag" but "it's difficult
// to find the scroll bar" — i.e. the user couldn't tell where they were
// on the map. On mount, scroll so the player's current tile is roughly
// centered in the viewport. Cheap, and solves the discoverability issue
// without adding a "find me" button.
//
// Coordinates: tiles render at 28px (mobile) or 36px (>=sm) square plus
// a 2px gap. We compute the midpoint in unit cells and multiply by the
// actual computed cell width so viewport scaling works on any device.

function centerOnCurrentTile() {
    const el = scrollContainer.value;
    if (!el || !props.bounds) return;

    const current = props.tiles.find((t) => t.id === props.current_tile_id);
    if (!current) return;

    // Find the first rendered tile child to measure the real cell width,
    // rather than hardcoding a size that drifts with the Tailwind classes.
    const firstCell = el.querySelector<HTMLElement>('[data-atlas-cell]');
    if (!firstCell) return;

    const cellRect = firstCell.getBoundingClientRect();
    const cellW = cellRect.width + 2; // +gap (0.5 rem ≈ 2px inside flex gap-0.5)
    const cellH = cellRect.height + 2;

    // Cell position inside the grid (in rendered pixels). The grid is
    // 0-indexed from the west/north corner of its own bounds.
    const col = current.x - props.bounds.min_x;
    const row = props.bounds.max_y - current.y; // rows are top-to-bottom (flipped y)

    const targetX = col * cellW + cellW / 2 - el.clientWidth / 2;
    const targetY = row * cellH + cellH / 2 - el.clientHeight / 2;

    el.scrollLeft = Math.max(0, targetX);
    el.scrollTop = Math.max(0, targetY);
}

onMounted(async () => {
    // Wait one tick so the grid is in the DOM before we measure it.
    await nextTick();
    centerOnCurrentTile();
    window.addEventListener('mouseup', onWindowMouseUp);
});

onBeforeUnmount(() => {
    window.removeEventListener('mouseup', onWindowMouseUp);
});
</script>

<template>
    <Head title="Explorer's Atlas — Clash Wars" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-mono text-xl font-bold uppercase tracking-wider text-amber-400">
                Explorer's Atlas
            </h2>
        </template>

        <div class="py-4 sm:py-8">
            <div class="max-w-6xl mx-auto px-3 sm:px-6 lg:px-8 space-y-4 sm:space-y-6">
                <!-- Locked state — player hasn't bought the atlas -->
                <div
                    v-if="!owns_atlas"
                    class="bg-zinc-900 border-2 border-zinc-800 rounded-lg p-6 sm:p-12 text-center font-mono"
                >
                    <div class="inline-flex mb-6 text-zinc-600">
                        <svg viewBox="0 0 48 48" class="w-20 h-20" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="10" y="14" width="28" height="26" rx="2" />
                            <path d="M10 20 L38 20" />
                            <path d="M20 14 L20 8 A4 4 0 0 1 28 8 L28 14" />
                            <circle cx="24" cy="28" r="2" fill="currentColor" />
                        </svg>
                    </div>
                    <h3 class="text-2xl font-bold text-zinc-100 mb-3">Atlas locked</h3>
                    <p class="text-zinc-400 max-w-xl mx-auto mb-6">
                        Buy the <span class="text-amber-400">Explorer's Atlas</span> at any
                        <span class="text-sky-400">General Store</span> post. It's a leather-bound
                        notebook that quietly sketches every tile you've walked on — and once you
                        own it, this page redraws them all as a grid. 30 oil barrels.
                    </p>
                    <Link
                        :href="route('map.show')"
                        class="inline-flex items-center rounded-md border border-zinc-700 px-4 py-2 font-mono text-sm uppercase tracking-wider text-zinc-300 hover:border-amber-400 hover:text-amber-400 transition"
                    >
                        Back to map
                    </Link>
                </div>

                <!-- Unlocked state — render the grid -->
                <template v-else>
                    <!-- Summary bar -->
                    <div class="bg-zinc-900 border border-zinc-800 rounded-lg p-3 sm:p-4 grid grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4 text-sm font-mono">
                        <div>
                            <div class="text-zinc-500 text-xs uppercase">Tiles discovered</div>
                            <div class="text-amber-400 text-lg">{{ tiles.length }}</div>
                        </div>
                        <div v-if="bounds">
                            <div class="text-zinc-500 text-xs uppercase">West edge</div>
                            <div class="text-zinc-100 text-lg">x = {{ bounds.min_x }}</div>
                        </div>
                        <div v-if="bounds">
                            <div class="text-zinc-500 text-xs uppercase">East edge</div>
                            <div class="text-zinc-100 text-lg">x = {{ bounds.max_x }}</div>
                        </div>
                        <div v-if="bounds">
                            <div class="text-zinc-500 text-xs uppercase">Span</div>
                            <div class="text-zinc-100 text-lg">{{ width }} × {{ height }}</div>
                        </div>
                    </div>

                    <!-- Legend -->
                    <div class="bg-zinc-900 border border-zinc-800 rounded-lg p-4 flex flex-wrap gap-4 text-xs font-mono text-zinc-400">
                        <div class="flex items-center gap-2">
                            <div class="w-5 h-5 rounded border border-amber-500 bg-amber-500/20"></div>
                            You are here
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-5 h-5 rounded border border-emerald-500 bg-emerald-500/20"></div>
                            Your base
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-5 h-5 rounded border border-zinc-800 bg-zinc-950"></div>
                            Undiscovered
                        </div>
                    </div>

                    <!-- Empty state — atlas owned but no tiles discovered (shouldn't happen post-spawn, but defensive) -->
                    <div
                        v-if="tiles.length === 0 || !bounds"
                        class="bg-zinc-900 border border-zinc-800 rounded-lg p-12 text-center font-mono text-zinc-500"
                    >
                        Nothing to show yet. Walk the dust and come back.
                    </div>

                    <!-- Atlas grid — click/touch-drag to pan, auto-centers
                         on your current tile on first render. Native
                         scrollbars and mouse wheel still work as before. -->
                    <div
                        v-else
                        ref="scrollContainer"
                        class="atlas-scroll bg-zinc-900 border border-zinc-800 rounded-lg p-2 sm:p-4 overflow-auto select-none"
                        :class="isDragging ? 'cursor-grabbing' : 'cursor-grab'"
                        @mousedown="onPointerDown"
                        @mousemove="onPointerMove"
                        @mouseup="onPointerUp"
                        @mouseleave="onPointerUp"
                        @touchstart.passive="onTouchStart"
                        @touchmove.passive="onTouchMove"
                        @touchend="onTouchEnd"
                        @touchcancel="onTouchEnd"
                    >
                        <div class="inline-block">
                            <div
                                v-for="y in rows"
                                :key="y"
                                class="flex gap-0.5 mb-0.5"
                            >
                                <div
                                    v-for="x in cols"
                                    :key="`${x}:${y}`"
                                    data-atlas-cell
                                    class="w-7 h-7 sm:w-9 sm:h-9 rounded border flex items-center justify-center relative shrink-0"
                                    :class="[
                                        tileByCoord[`${x}:${y}`]
                                            ? (isCurrent(tileByCoord[`${x}:${y}`])
                                                ? 'border-amber-500 bg-amber-500/20'
                                                : isBase(tileByCoord[`${x}:${y}`])
                                                    ? 'border-emerald-500 bg-emerald-500/20'
                                                    : 'border-zinc-800 bg-zinc-800/40')
                                            : 'border-zinc-900 bg-zinc-950'
                                    ]"
                                    :title="tileByCoord[`${x}:${y}`]
                                        ? `(${x}, ${y}) — ${tileByCoord[`${x}:${y}`].type}${tileByCoord[`${x}:${y}`].subtype ? ' / ' + tileByCoord[`${x}:${y}`].subtype : ''}`
                                        : `(${x}, ${y}) — undiscovered`"
                                >
                                    <TileIcon
                                        v-if="tileByCoord[`${x}:${y}`]"
                                        :type="isBase(tileByCoord[`${x}:${y}`]) ? 'base' : tileByCoord[`${x}:${y}`].type"
                                        class="w-4 h-4 sm:w-6 sm:h-6 pointer-events-none"
                                        :class="tileColor(isBase(tileByCoord[`${x}:${y}`]) ? 'base' : tileByCoord[`${x}:${y}`].type)"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="text-zinc-500 text-xs font-mono">
                        North is up. Hover a tile for coordinates &mdash; click and drag to pan.
                    </div>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
