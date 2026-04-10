<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TileIcon from '@/Components/TileIcon.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

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
</script>

<template>
    <Head title="Explorer's Atlas — Cash Clash" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-mono text-xl font-bold uppercase tracking-wider text-amber-400">
                Explorer's Atlas
            </h2>
        </template>

        <div class="py-8">
            <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <!-- Locked state — player hasn't bought the atlas -->
                <div
                    v-if="!owns_atlas"
                    class="bg-zinc-900 border-2 border-zinc-800 rounded-lg p-12 text-center font-mono"
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
                    <div class="bg-zinc-900 border border-zinc-800 rounded-lg p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm font-mono">
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

                    <!-- Atlas grid -->
                    <div
                        v-else
                        class="bg-zinc-900 border border-zinc-800 rounded-lg p-4 overflow-auto"
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
                                    class="w-9 h-9 rounded border flex items-center justify-center relative"
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
                                        class="w-6 h-6"
                                        :class="tileColor(isBase(tileByCoord[`${x}:${y}`]) ? 'base' : tileByCoord[`${x}:${y}`].type)"
                                    />
                                </div>
                            </div>
                        </div>
                        <div class="text-zinc-500 text-xs font-mono mt-3">
                            North is up. Hover a tile for coordinates.
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
