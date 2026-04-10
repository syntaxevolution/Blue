<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TileIcon from '@/Components/TileIcon.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

interface PlayerState {
    id: number;
    akzar_cash: number;
    oil_barrels: number;
    intel: number;
    moves_current: number;
    strength: number;
    fortification: number;
    stealth: number;
    security: number;
    drill_tier: number;
    immunity_expires_at: string | null;
    base_tile_id: number;
}

interface TileInfo {
    id: number;
    x: number;
    y: number;
    type: string;
    subtype: string | null;
    flavor_text: string | null;
    is_own_base: boolean;
}

interface Neighbor {
    x: number;
    y: number;
    type: string;
    direction: 'n' | 's' | 'e' | 'w' | null;
}

interface DrillCell {
    grid_x: number;
    grid_y: number;
    quality: string; // dry | trickle | standard | gusher | depleted
    drilled: boolean;
}

interface OilFieldDetail {
    kind: 'oil_field';
    grid: DrillCell[];
}

interface PostDetail {
    kind: 'post';
    post_type: string | null;
    name: string | null;
}

interface OwnBaseDetail {
    kind: 'own_base';
    stored_cash: number;
    stored_oil_barrels: number;
    stored_intel: number;
}

interface EnemyBaseDetail {
    kind: 'enemy_base';
}

type TileDetail = OilFieldDetail | PostDetail | OwnBaseDetail | EnemyBaseDetail | null;

interface MapState {
    player: PlayerState;
    current_tile: TileInfo;
    tile_detail: TileDetail;
    neighbors: Neighbor[];
    discovered_count: number;
    bank_cap: number;
}

const props = defineProps<{
    state: MapState;
}>();

const page = usePage();
const errors = computed(() => (page.props.errors as Record<string, string>) ?? {});
const travelError = computed(() => errors.value.travel ?? null);
const drillError = computed(() => errors.value.drill ?? null);
const drillResult = computed(() => (page.props.flash as Record<string, string>)?.drill_result ?? null);

const neighborByDirection = computed<Record<string, Neighbor | null>>(() => {
    const map: Record<string, Neighbor | null> = { n: null, s: null, e: null, w: null };
    for (const n of props.state.neighbors) {
        if (n.direction) map[n.direction] = n;
    }
    return map;
});

function travel(direction: 'n' | 's' | 'e' | 'w') {
    router.post(
        route('map.move'),
        { direction },
        { preserveScroll: true, preserveState: false },
    );
}

function drill(cell: DrillCell) {
    if (cell.drilled) return;
    router.post(
        route('map.drill'),
        { grid_x: cell.grid_x, grid_y: cell.grid_y },
        { preserveScroll: true, preserveState: false },
    );
}

function tileLabel(tile: TileInfo): string {
    if (tile.is_own_base) return 'Your base';
    if (tile.subtype) return `${tile.type} / ${tile.subtype}`;
    return tile.type;
}

// Tile type → themed color class. Used for both the hero icon and the
// compass neighbor icons so every tile type has a consistent palette
// across the map view.
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

const immunityActive = computed(() => {
    if (!props.state.player.immunity_expires_at) return false;
    return new Date(props.state.player.immunity_expires_at) > new Date();
});

// Build a 5×5 grid map for O(1) lookup in the template.
const drillGridMap = computed<Record<string, DrillCell>>(() => {
    const result: Record<string, DrillCell> = {};
    if (props.state.tile_detail?.kind === 'oil_field') {
        for (const cell of props.state.tile_detail.grid) {
            result[`${cell.grid_x}:${cell.grid_y}`] = cell;
        }
    }
    return result;
});

function qualityClass(cell: DrillCell | undefined): string {
    if (!cell) return 'bg-zinc-800 border-zinc-700';
    if (cell.drilled) {
        return 'bg-zinc-950 border-zinc-800 text-zinc-700 cursor-not-allowed';
    }
    // Quality is hidden to the player — show a generic "unknown" state
    // with the same look regardless of actual quality. (Seismic Reading
    // items will reveal quality in Phase 5+.)
    return 'bg-zinc-800 border-zinc-700 hover:border-amber-400 hover:bg-zinc-700 text-zinc-500 hover:text-amber-400 cursor-pointer';
}

function qualityLabel(cell: DrillCell | undefined): string {
    if (!cell) return '·';
    if (cell.drilled) return '✕';
    return '?';
}
</script>

<template>
    <Head title="Map — Cash Clash" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-mono text-xl font-bold uppercase tracking-wider text-amber-400">
                Akzar — Map
            </h2>
        </template>

        <div class="py-8">
            <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <!-- Stats bar -->
                <div class="bg-zinc-900 border border-zinc-800 rounded-lg p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm font-mono">
                    <div>
                        <div class="text-zinc-500 text-xs uppercase">Akzar Cash</div>
                        <div class="text-amber-400 text-lg">A{{ state.player.akzar_cash.toFixed(2) }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-500 text-xs uppercase">Oil Barrels</div>
                        <div class="text-zinc-100 text-lg">{{ state.player.oil_barrels }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-500 text-xs uppercase">Intel</div>
                        <div class="text-zinc-100 text-lg">{{ state.player.intel }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-500 text-xs uppercase">Moves</div>
                        <div class="text-zinc-100 text-lg">
                            {{ state.player.moves_current }}<span class="text-zinc-600">/{{ state.bank_cap }}</span>
                        </div>
                    </div>
                </div>

                <!-- Stats -->
                <div class="bg-zinc-900 border border-zinc-800 rounded-lg p-4 grid grid-cols-4 gap-4 text-sm font-mono">
                    <div class="text-center">
                        <div class="text-zinc-500 text-xs uppercase">Strength</div>
                        <div class="text-rose-400 text-xl">{{ state.player.strength }}</div>
                    </div>
                    <div class="text-center">
                        <div class="text-zinc-500 text-xs uppercase">Fort</div>
                        <div class="text-emerald-400 text-xl">{{ state.player.fortification }}</div>
                    </div>
                    <div class="text-center">
                        <div class="text-zinc-500 text-xs uppercase">Stealth</div>
                        <div class="text-violet-400 text-xl">{{ state.player.stealth }}</div>
                    </div>
                    <div class="text-center">
                        <div class="text-zinc-500 text-xs uppercase">Security</div>
                        <div class="text-sky-400 text-xl">{{ state.player.security }}</div>
                    </div>
                </div>

                <!-- Immunity banner -->
                <div
                    v-if="immunityActive"
                    class="bg-amber-950/50 border border-amber-700/50 rounded-lg p-3 text-amber-300 text-sm font-mono"
                >
                    New player immunity active until {{ state.player.immunity_expires_at }} — you cannot be attacked.
                </div>

                <!-- Flash messages -->
                <div
                    v-if="drillResult"
                    class="bg-emerald-950/50 border border-emerald-700/50 rounded-lg p-3 text-emerald-300 text-sm font-mono"
                >
                    {{ drillResult }}
                </div>
                <div
                    v-if="travelError"
                    class="bg-rose-950/50 border border-rose-700/50 rounded-lg p-3 text-rose-300 text-sm font-mono"
                >
                    {{ travelError }}
                </div>
                <div
                    v-if="drillError"
                    class="bg-rose-950/50 border border-rose-700/50 rounded-lg p-3 text-rose-300 text-sm font-mono"
                >
                    {{ drillError }}
                </div>

                <!-- YOU ARE HERE — hero card showing the tile the player is currently standing on -->
                <div class="bg-zinc-900 border-2 border-amber-500/40 rounded-lg p-6 font-mono shadow-xl shadow-amber-900/10">
                    <div class="text-amber-400 text-xs uppercase tracking-[0.3em] mb-4 flex items-center gap-2">
                        <span class="inline-block h-2 w-2 rounded-full bg-amber-400 animate-pulse"></span>
                        You are here
                    </div>

                    <div class="flex items-start gap-6">
                        <!-- Big tile icon -->
                        <div
                            class="shrink-0 rounded-lg border border-amber-500/30 bg-amber-500/5 p-5"
                            :class="tileColor(state.current_tile.is_own_base ? 'base' : state.current_tile.type)"
                        >
                            <TileIcon
                                :type="state.current_tile.is_own_base ? 'base' : state.current_tile.type"
                                class="w-24 h-24"
                            />
                        </div>

                        <div class="flex-1 min-w-0">
                            <div class="text-zinc-500 text-xs uppercase mb-1">Coordinates</div>
                            <div class="text-amber-400 text-lg mb-3">
                                ({{ state.current_tile.x }}, {{ state.current_tile.y }})
                                <span class="text-zinc-600 text-xs ml-2">· tile #{{ state.current_tile.id }}</span>
                            </div>
                            <div class="text-zinc-500 text-xs uppercase mb-1">Tile type</div>
                            <div class="text-zinc-100 text-3xl font-bold mb-2">{{ tileLabel(state.current_tile) }}</div>
                            <div v-if="state.current_tile.flavor_text" class="text-zinc-400 italic">
                                {{ state.current_tile.flavor_text }}
                            </div>
                        </div>
                    </div>

                    <!-- Tile-specific interaction panel -->
                    <div class="my-6">
                        <!-- Oil field: 5×5 drill grid -->
                        <div v-if="state.tile_detail?.kind === 'oil_field'">
                            <div class="text-zinc-500 text-xs uppercase mb-3">
                                Drill grid — 2 moves per cell
                            </div>
                            <div class="inline-block">
                                <div
                                    v-for="y in [4, 3, 2, 1, 0]"
                                    :key="y"
                                    class="flex gap-1 mb-1"
                                >
                                    <button
                                        v-for="x in [0, 1, 2, 3, 4]"
                                        :key="`${x}:${y}`"
                                        type="button"
                                        class="w-12 h-12 rounded border flex items-center justify-center text-lg transition"
                                        :class="qualityClass(drillGridMap[`${x}:${y}`])"
                                        :disabled="drillGridMap[`${x}:${y}`]?.drilled"
                                        @click="drill(drillGridMap[`${x}:${y}`])"
                                        :title="`(${x}, ${y})`"
                                    >
                                        {{ qualityLabel(drillGridMap[`${x}:${y}`]) }}
                                    </button>
                                </div>
                            </div>
                            <div class="text-zinc-500 text-xs mt-2">
                                ? = undrilled · ✕ = depleted (regenerates over time)
                            </div>
                        </div>

                        <!-- Post: shop placeholder -->
                        <div v-else-if="state.tile_detail?.kind === 'post'" class="rounded border border-zinc-800 bg-zinc-950/50 p-4">
                            <div class="text-zinc-500 text-xs uppercase mb-1">
                                {{ state.tile_detail.post_type }} post
                            </div>
                            <div class="text-amber-400 text-lg mb-2">
                                {{ state.tile_detail.name }}
                            </div>
                            <div class="text-zinc-500 text-sm italic">
                                Shop inventory coming soon — no items for sale yet.
                            </div>
                        </div>

                        <!-- Own base: vault summary -->
                        <div v-else-if="state.tile_detail?.kind === 'own_base'" class="rounded border border-zinc-800 bg-zinc-950/50 p-4">
                            <div class="text-zinc-500 text-xs uppercase mb-3">Your base vault</div>
                            <div class="grid grid-cols-3 gap-4 text-sm">
                                <div>
                                    <div class="text-zinc-500 text-xs">Stored cash</div>
                                    <div class="text-amber-400 text-lg">A{{ state.tile_detail.stored_cash.toFixed(2) }}</div>
                                </div>
                                <div>
                                    <div class="text-zinc-500 text-xs">Oil barrels</div>
                                    <div class="text-zinc-100 text-lg">{{ state.tile_detail.stored_oil_barrels }}</div>
                                </div>
                                <div>
                                    <div class="text-zinc-500 text-xs">Intel</div>
                                    <div class="text-zinc-100 text-lg">{{ state.tile_detail.stored_intel }}</div>
                                </div>
                            </div>
                            <div class="text-zinc-500 text-sm italic mt-3">
                                Fortifications, items, and base management coming soon.
                            </div>
                        </div>

                        <!-- Enemy base: no-op for now -->
                        <div v-else-if="state.tile_detail?.kind === 'enemy_base'" class="rounded border border-zinc-800 bg-zinc-950/50 p-4">
                            <div class="text-rose-400 text-sm">
                                Enemy base. Spy and attack actions coming in Phase 3.
                            </div>
                        </div>

                        <!-- Everything else (wasteland, landmark): no panel, just flavor -->
                        <div v-else class="text-zinc-600 text-sm italic">
                            Nothing to do here but keep walking.
                        </div>
                    </div>

                    <!-- Compass pad — purely for navigation. Center is a compass rose, not
                         a duplicate of the hero card above. -->
                    <div class="mt-8">
                        <div class="text-zinc-500 text-xs uppercase tracking-widest mb-3 text-center">
                            Travel (1 move per step)
                        </div>
                        <div class="grid grid-cols-3 gap-2 max-w-sm mx-auto">
                            <div></div>
                            <button
                                type="button"
                                class="bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 hover:border-amber-400 text-zinc-100 rounded flex flex-col items-center justify-center gap-1 py-2 disabled:opacity-30 disabled:cursor-not-allowed transition"
                                :disabled="!neighborByDirection.n"
                                @click="travel('n')"
                            >
                                <TileIcon
                                    v-if="neighborByDirection.n"
                                    :type="neighborByDirection.n.type"
                                    class="w-7 h-7"
                                    :class="tileColor(neighborByDirection.n.type)"
                                />
                                <span class="text-xs text-zinc-400">N · {{ neighborByDirection.n?.type ?? '—' }}</span>
                            </button>
                            <div></div>

                            <button
                                type="button"
                                class="bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 hover:border-amber-400 text-zinc-100 rounded flex flex-col items-center justify-center gap-1 py-2 disabled:opacity-30 disabled:cursor-not-allowed transition"
                                :disabled="!neighborByDirection.w"
                                @click="travel('w')"
                            >
                                <TileIcon
                                    v-if="neighborByDirection.w"
                                    :type="neighborByDirection.w.type"
                                    class="w-7 h-7"
                                    :class="tileColor(neighborByDirection.w.type)"
                                />
                                <span class="text-xs text-zinc-400">W · {{ neighborByDirection.w?.type ?? '—' }}</span>
                            </button>
                            <div class="flex items-center justify-center">
                                <!-- Decorative compass rose — not clickable. The real
                                     "you are here" display is the hero card above. -->
                                <svg
                                    viewBox="0 0 48 48"
                                    class="w-8 h-8 text-zinc-700"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                >
                                    <circle cx="24" cy="24" r="20" />
                                    <path d="M24 6 L24 42 M6 24 L42 24" />
                                    <path d="M24 6 L20 14 L24 12 L28 14 Z" fill="currentColor" />
                                </svg>
                            </div>
                            <button
                                type="button"
                                class="bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 hover:border-amber-400 text-zinc-100 rounded flex flex-col items-center justify-center gap-1 py-2 disabled:opacity-30 disabled:cursor-not-allowed transition"
                                :disabled="!neighborByDirection.e"
                                @click="travel('e')"
                            >
                                <TileIcon
                                    v-if="neighborByDirection.e"
                                    :type="neighborByDirection.e.type"
                                    class="w-7 h-7"
                                    :class="tileColor(neighborByDirection.e.type)"
                                />
                                <span class="text-xs text-zinc-400">E · {{ neighborByDirection.e?.type ?? '—' }}</span>
                            </button>

                            <div></div>
                            <button
                                type="button"
                                class="bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 hover:border-amber-400 text-zinc-100 rounded flex flex-col items-center justify-center gap-1 py-2 disabled:opacity-30 disabled:cursor-not-allowed transition"
                                :disabled="!neighborByDirection.s"
                                @click="travel('s')"
                            >
                                <TileIcon
                                    v-if="neighborByDirection.s"
                                    :type="neighborByDirection.s.type"
                                    class="w-7 h-7"
                                    :class="tileColor(neighborByDirection.s.type)"
                                />
                                <span class="text-xs text-zinc-400">S · {{ neighborByDirection.s?.type ?? '—' }}</span>
                            </button>
                            <div></div>
                        </div>
                    </div>

                    <div class="mt-6 text-zinc-500 text-xs text-center">
                        Tiles discovered: {{ state.discovered_count }}
                    </div>
                </div>

                <!-- Debug panel — temporary diagnostic. Expand to see raw
                     server state so we can verify the backend is sending
                     the tile type you expect after travel. -->
                <details class="bg-zinc-900/60 border border-zinc-800 rounded p-4 font-mono text-xs text-zinc-400">
                    <summary class="cursor-pointer text-zinc-500 uppercase tracking-widest">
                        Debug · raw server state
                    </summary>
                    <pre class="mt-3 overflow-auto text-[11px] leading-relaxed">{{ JSON.stringify(state, null, 2) }}</pre>
                </details>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
