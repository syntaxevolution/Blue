<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
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
}

interface TileInfo {
    id: number;
    x: number;
    y: number;
    type: string;
    subtype: string | null;
    flavor_text: string | null;
}

interface Neighbor {
    x: number;
    y: number;
    type: string;
    direction: 'n' | 's' | 'e' | 'w' | null;
}

interface MapState {
    player: PlayerState;
    current_tile: TileInfo;
    neighbors: Neighbor[];
    discovered_count: number;
    bank_cap: number;
}

const props = defineProps<{
    state: MapState;
}>();

const page = usePage();
const travelError = computed(() => (page.props.errors as Record<string, string>)?.travel ?? null);

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

function tileLabel(tile: TileInfo | Neighbor): string {
    if ('subtype' in tile && tile.subtype) {
        return `${tile.type} / ${tile.subtype}`;
    }
    return tile.type;
}

const immunityActive = computed(() => {
    if (!props.state.player.immunity_expires_at) return false;
    return new Date(props.state.player.immunity_expires_at) > new Date();
});
</script>

<template>
    <Head title="Map — Akzar" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-amber-400 leading-tight">
                Akzar — Map
            </h2>
        </template>

        <div class="py-8">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
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

                <!-- Travel error -->
                <div
                    v-if="travelError"
                    class="bg-rose-950/50 border border-rose-700/50 rounded-lg p-3 text-rose-300 text-sm font-mono"
                >
                    {{ travelError }}
                </div>

                <!-- Current tile + move buttons -->
                <div class="bg-zinc-900 border border-zinc-800 rounded-lg p-6 font-mono">
                    <div class="text-zinc-500 text-xs uppercase mb-1">Current tile</div>
                    <div class="text-amber-400 text-lg mb-1">
                        ({{ state.current_tile.x }}, {{ state.current_tile.y }})
                    </div>
                    <div class="text-zinc-100 text-2xl mb-2">{{ tileLabel(state.current_tile) }}</div>
                    <div v-if="state.current_tile.flavor_text" class="text-zinc-400 italic mb-4">
                        {{ state.current_tile.flavor_text }}
                    </div>

                    <!-- Compass pad -->
                    <div class="mt-6 grid grid-cols-3 gap-2 max-w-xs mx-auto">
                        <div></div>
                        <button
                            type="button"
                            class="bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 text-zinc-100 py-3 rounded disabled:opacity-30 disabled:cursor-not-allowed"
                            :disabled="!neighborByDirection.n"
                            @click="travel('n')"
                        >
                            N
                            <div class="text-xs text-zinc-500" v-if="neighborByDirection.n">
                                {{ neighborByDirection.n.type }}
                            </div>
                        </button>
                        <div></div>

                        <button
                            type="button"
                            class="bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 text-zinc-100 py-3 rounded disabled:opacity-30 disabled:cursor-not-allowed"
                            :disabled="!neighborByDirection.w"
                            @click="travel('w')"
                        >
                            W
                            <div class="text-xs text-zinc-500" v-if="neighborByDirection.w">
                                {{ neighborByDirection.w.type }}
                            </div>
                        </button>
                        <div class="flex items-center justify-center text-zinc-600 text-xs">
                            YOU
                        </div>
                        <button
                            type="button"
                            class="bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 text-zinc-100 py-3 rounded disabled:opacity-30 disabled:cursor-not-allowed"
                            :disabled="!neighborByDirection.e"
                            @click="travel('e')"
                        >
                            E
                            <div class="text-xs text-zinc-500" v-if="neighborByDirection.e">
                                {{ neighborByDirection.e.type }}
                            </div>
                        </button>

                        <div></div>
                        <button
                            type="button"
                            class="bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 text-zinc-100 py-3 rounded disabled:opacity-30 disabled:cursor-not-allowed"
                            :disabled="!neighborByDirection.s"
                            @click="travel('s')"
                        >
                            S
                            <div class="text-xs text-zinc-500" v-if="neighborByDirection.s">
                                {{ neighborByDirection.s.type }}
                            </div>
                        </button>
                        <div></div>
                    </div>

                    <div class="mt-6 text-zinc-500 text-xs text-center">
                        Tiles discovered: {{ state.discovered_count }}
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
