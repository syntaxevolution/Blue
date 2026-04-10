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

interface Effects {
    stat_add?: Partial<Record<'strength' | 'fortification' | 'stealth' | 'security', number>>;
    set_drill_tier?: number;
    [key: string]: unknown;
}

interface OwnedItem {
    key: string;
    name: string;
    description: string | null;
    post_type: string;
    quantity: number;
    effects: Effects | null;
}

interface ShopItem {
    key: string;
    name: string;
    description: string | null;
    price_barrels: number;
    price_cash: number;
    price_intel: number;
    effects: Effects | null;
    can_afford: boolean;
}

interface DrillCell {
    grid_x: number;
    grid_y: number;
    quality: string;
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
    items: ShopItem[];
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
    owned_items: OwnedItem[];
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
const purchaseError = computed(() => errors.value.purchase ?? null);
const flash = computed(() => (page.props.flash as Record<string, string>) ?? {});
const drillResult = computed(() => flash.value.drill_result ?? null);
const purchaseResult = computed(() => flash.value.purchase_result ?? null);

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

function buy(item: ShopItem) {
    if (!item.can_afford) return;
    router.post(
        route('map.purchase'),
        { item_key: item.key },
        { preserveScroll: true, preserveState: false },
    );
}

function tileLabel(tile: TileInfo): string {
    if (tile.is_own_base) return 'Your base';
    if (tile.subtype) return `${tile.type} / ${tile.subtype}`;
    return tile.type;
}

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

const drillGridMap = computed<Record<string, DrillCell>>(() => {
    const result: Record<string, DrillCell> = {};
    if (props.state.tile_detail?.kind === 'oil_field') {
        for (const cell of props.state.tile_detail.grid) {
            result[`${cell.grid_x}:${cell.grid_y}`] = cell;
        }
    }
    return result;
});

function drillCellClass(cell: DrillCell | undefined): string {
    if (!cell) return 'bg-zinc-800 border-zinc-700';
    if (cell.drilled) {
        return 'bg-zinc-950 border-zinc-800 text-zinc-700 cursor-not-allowed';
    }
    return 'bg-zinc-800 border-zinc-700 hover:border-amber-400 hover:bg-zinc-700 text-zinc-500 hover:text-amber-400 cursor-pointer';
}

function drillCellLabel(cell: DrillCell | undefined): string {
    if (!cell) return '·';
    if (cell.drilled) return '✕';
    return '?';
}

function formatEffects(effects: Effects | null): string[] {
    if (!effects) return [];
    const parts: string[] = [];
    if (effects.stat_add) {
        const labels: Record<string, string> = {
            strength: 'Strength',
            fortification: 'Fort',
            stealth: 'Stealth',
            security: 'Security',
        };
        for (const [stat, delta] of Object.entries(effects.stat_add)) {
            if (typeof delta === 'number' && delta !== 0) {
                parts.push(`${delta >= 0 ? '+' : ''}${delta} ${labels[stat] ?? stat}`);
            }
        }
    }
    if (typeof effects.set_drill_tier === 'number') {
        parts.push(`Drill tier ${effects.set_drill_tier}`);
    }
    return parts;
}

function formatPrice(item: ShopItem): string {
    const parts: string[] = [];
    if (item.price_barrels > 0) parts.push(`${item.price_barrels} barrels`);
    if (item.price_cash > 0) parts.push(`A${item.price_cash.toFixed(2)}`);
    if (item.price_intel > 0) parts.push(`${item.price_intel} intel`);
    return parts.length ? parts.join(' · ') : 'Free';
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
                <!-- Top resource bar -->
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

                <!-- Stats row -->
                <div class="bg-zinc-900 border border-zinc-800 rounded-lg p-4 grid grid-cols-5 gap-4 text-sm font-mono">
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
                    <div class="text-center">
                        <div class="text-zinc-500 text-xs uppercase">Drill</div>
                        <div class="text-amber-400 text-xl">T{{ state.player.drill_tier }}</div>
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
                <div v-if="drillResult" class="bg-emerald-950/50 border border-emerald-700/50 rounded-lg p-3 text-emerald-300 text-sm font-mono">
                    {{ drillResult }}
                </div>
                <div v-if="purchaseResult" class="bg-emerald-950/50 border border-emerald-700/50 rounded-lg p-3 text-emerald-300 text-sm font-mono">
                    {{ purchaseResult }}
                </div>
                <div v-if="travelError" class="bg-rose-950/50 border border-rose-700/50 rounded-lg p-3 text-rose-300 text-sm font-mono">
                    {{ travelError }}
                </div>
                <div v-if="drillError" class="bg-rose-950/50 border border-rose-700/50 rounded-lg p-3 text-rose-300 text-sm font-mono">
                    {{ drillError }}
                </div>
                <div v-if="purchaseError" class="bg-rose-950/50 border border-rose-700/50 rounded-lg p-3 text-rose-300 text-sm font-mono">
                    {{ purchaseError }}
                </div>

                <!-- Your gear — always visible, collapsible -->
                <details class="bg-zinc-900 border border-zinc-800 rounded-lg font-mono" open>
                    <summary class="cursor-pointer p-4 text-zinc-500 text-xs uppercase tracking-widest flex items-center justify-between">
                        <span>Your gear ({{ state.owned_items.length }})</span>
                        <span class="text-zinc-600">click to toggle</span>
                    </summary>
                    <div class="border-t border-zinc-800 p-4">
                        <div v-if="state.owned_items.length === 0" class="text-zinc-500 text-sm italic">
                            Nothing yet. Drill for oil barrels, then spend them at posts to upgrade your stats and drill rig.
                        </div>
                        <div v-else class="space-y-2">
                            <div
                                v-for="item in state.owned_items"
                                :key="item.key"
                                class="flex items-start justify-between gap-4 py-2 border-b border-zinc-800 last:border-0"
                            >
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-baseline gap-2">
                                        <span class="text-zinc-100 text-sm font-bold">{{ item.name }}</span>
                                        <span v-if="item.quantity > 1" class="text-zinc-500 text-xs">× {{ item.quantity }}</span>
                                        <span class="text-zinc-600 text-xs uppercase tracking-widest">{{ item.post_type }}</span>
                                    </div>
                                    <div v-if="item.description" class="text-zinc-500 text-xs mt-0.5">
                                        {{ item.description }}
                                    </div>
                                </div>
                                <div class="shrink-0 text-right">
                                    <div
                                        v-for="(effect, idx) in formatEffects(item.effects)"
                                        :key="idx"
                                        class="text-emerald-400 text-xs"
                                    >
                                        {{ effect }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </details>

                <!-- MAIN MAP PANEL — compass with current tile interaction in the center -->
                <div class="bg-zinc-900 border-2 border-amber-500/40 rounded-lg p-4 md:p-6 font-mono shadow-xl shadow-amber-900/10">
                    <!-- N button on top -->
                    <button
                        type="button"
                        class="w-full bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 hover:border-amber-400 rounded flex items-center justify-center gap-3 py-3 disabled:opacity-30 disabled:cursor-not-allowed transition mb-3"
                        :disabled="!neighborByDirection.n"
                        @click="travel('n')"
                    >
                        <span class="text-amber-400 text-2xl">↑</span>
                        <TileIcon
                            v-if="neighborByDirection.n"
                            :type="neighborByDirection.n.type"
                            class="w-8 h-8"
                            :class="tileColor(neighborByDirection.n.type)"
                        />
                        <div class="text-left">
                            <div class="text-xs text-zinc-500 uppercase tracking-widest">North</div>
                            <div class="text-sm text-zinc-300">{{ neighborByDirection.n?.type ?? '— edge —' }}</div>
                        </div>
                    </button>

                    <!-- Middle row: W | CENTER | E -->
                    <div class="flex items-stretch gap-3">
                        <!-- W button on left -->
                        <button
                            type="button"
                            class="w-20 md:w-28 shrink-0 bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 hover:border-amber-400 rounded flex flex-col items-center justify-center gap-2 px-2 py-4 disabled:opacity-30 disabled:cursor-not-allowed transition"
                            :disabled="!neighborByDirection.w"
                            @click="travel('w')"
                        >
                            <span class="text-amber-400 text-2xl">←</span>
                            <TileIcon
                                v-if="neighborByDirection.w"
                                :type="neighborByDirection.w.type"
                                class="w-8 h-8"
                                :class="tileColor(neighborByDirection.w.type)"
                            />
                            <div class="text-xs text-zinc-500 uppercase tracking-widest">West</div>
                            <div class="text-xs text-zinc-300 break-words text-center">
                                {{ neighborByDirection.w?.type ?? '— edge —' }}
                            </div>
                        </button>

                        <!-- CENTER — current tile + interaction panel -->
                        <div class="flex-1 min-w-0 bg-zinc-950/60 border border-amber-500/20 rounded-lg p-5 flex flex-col items-center text-center">
                            <div class="text-amber-400 text-xs uppercase tracking-[0.3em] mb-3 flex items-center gap-2">
                                <span class="inline-block h-2 w-2 rounded-full bg-amber-400 animate-pulse"></span>
                                You are here
                            </div>

                            <div
                                class="rounded-lg border border-amber-500/30 bg-amber-500/5 p-4 mb-3"
                                :class="tileColor(state.current_tile.is_own_base ? 'base' : state.current_tile.type)"
                            >
                                <TileIcon
                                    :type="state.current_tile.is_own_base ? 'base' : state.current_tile.type"
                                    class="w-20 h-20"
                                />
                            </div>

                            <div class="text-amber-400 text-sm mb-1">
                                ({{ state.current_tile.x }}, {{ state.current_tile.y }})
                                <span class="text-zinc-600 text-xs ml-1">· tile #{{ state.current_tile.id }}</span>
                            </div>
                            <div class="text-zinc-100 text-2xl font-bold mb-1">
                                {{ tileLabel(state.current_tile) }}
                            </div>
                            <div v-if="state.current_tile.flavor_text" class="text-zinc-400 italic text-sm mb-4">
                                {{ state.current_tile.flavor_text }}
                            </div>

                            <!-- Tile-specific interaction panel -->
                            <div class="w-full mt-4">
                                <!-- Oil field: 5×5 drill grid -->
                                <div v-if="state.tile_detail?.kind === 'oil_field'">
                                    <div class="text-zinc-500 text-xs uppercase mb-3 tracking-widest">
                                        Drill grid — 2 moves per cell
                                    </div>
                                    <div class="inline-block">
                                        <div v-for="y in [4, 3, 2, 1, 0]" :key="y" class="flex gap-1 mb-1 justify-center">
                                            <button
                                                v-for="x in [0, 1, 2, 3, 4]"
                                                :key="`${x}:${y}`"
                                                type="button"
                                                class="w-11 h-11 rounded border flex items-center justify-center text-lg transition"
                                                :class="drillCellClass(drillGridMap[`${x}:${y}`])"
                                                :disabled="drillGridMap[`${x}:${y}`]?.drilled"
                                                @click="drill(drillGridMap[`${x}:${y}`])"
                                                :title="`(${x}, ${y})`"
                                            >
                                                {{ drillCellLabel(drillGridMap[`${x}:${y}`]) }}
                                            </button>
                                        </div>
                                    </div>
                                    <div class="text-zinc-500 text-xs mt-3">
                                        ? = undrilled · ✕ = depleted
                                    </div>
                                </div>

                                <!-- Post: shop items list -->
                                <div v-else-if="state.tile_detail?.kind === 'post'" class="text-left">
                                    <div class="text-zinc-500 text-xs uppercase mb-3 tracking-widest text-center">
                                        {{ state.tile_detail.post_type }} post · inventory
                                    </div>
                                    <div v-if="state.tile_detail.items.length === 0" class="text-zinc-500 text-sm italic text-center">
                                        No items for sale at this post type yet.
                                    </div>
                                    <div v-else class="space-y-2">
                                        <div
                                            v-for="item in state.tile_detail.items"
                                            :key="item.key"
                                            class="bg-zinc-900 border border-zinc-800 rounded p-3 flex items-start justify-between gap-3"
                                        >
                                            <div class="flex-1 min-w-0">
                                                <div class="text-zinc-100 text-sm font-bold">{{ item.name }}</div>
                                                <div v-if="item.description" class="text-zinc-500 text-xs mt-0.5">
                                                    {{ item.description }}
                                                </div>
                                                <div class="text-emerald-400 text-xs mt-1">
                                                    <span v-for="(effect, idx) in formatEffects(item.effects)" :key="idx" class="mr-2">
                                                        {{ effect }}
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="shrink-0 flex flex-col items-end gap-2">
                                                <div class="text-amber-400 text-xs font-bold whitespace-nowrap">
                                                    {{ formatPrice(item) }}
                                                </div>
                                                <button
                                                    type="button"
                                                    class="bg-amber-500 hover:bg-amber-400 text-zinc-950 text-xs font-bold uppercase tracking-wider px-3 py-1 rounded transition disabled:opacity-30 disabled:cursor-not-allowed"
                                                    :disabled="!item.can_afford"
                                                    @click="buy(item)"
                                                >
                                                    Buy
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Own base: vault summary -->
                                <div v-else-if="state.tile_detail?.kind === 'own_base'" class="rounded border border-zinc-800 bg-zinc-900 p-4">
                                    <div class="text-zinc-500 text-xs uppercase mb-3 tracking-widest">Your base vault</div>
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
                                </div>

                                <!-- Enemy base -->
                                <div v-else-if="state.tile_detail?.kind === 'enemy_base'" class="rounded border border-zinc-800 bg-zinc-900 p-4">
                                    <div class="text-rose-400 text-sm">
                                        Enemy base. Spy and attack actions coming in Phase 3.
                                    </div>
                                </div>

                                <!-- Wasteland / landmark / ruin / auction -->
                                <div v-else class="text-zinc-600 text-sm italic">
                                    Nothing to do here but keep walking.
                                </div>
                            </div>
                        </div>

                        <!-- E button on right -->
                        <button
                            type="button"
                            class="w-20 md:w-28 shrink-0 bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 hover:border-amber-400 rounded flex flex-col items-center justify-center gap-2 px-2 py-4 disabled:opacity-30 disabled:cursor-not-allowed transition"
                            :disabled="!neighborByDirection.e"
                            @click="travel('e')"
                        >
                            <span class="text-amber-400 text-2xl">→</span>
                            <TileIcon
                                v-if="neighborByDirection.e"
                                :type="neighborByDirection.e.type"
                                class="w-8 h-8"
                                :class="tileColor(neighborByDirection.e.type)"
                            />
                            <div class="text-xs text-zinc-500 uppercase tracking-widest">East</div>
                            <div class="text-xs text-zinc-300 break-words text-center">
                                {{ neighborByDirection.e?.type ?? '— edge —' }}
                            </div>
                        </button>
                    </div>

                    <!-- S button on bottom -->
                    <button
                        type="button"
                        class="w-full bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 hover:border-amber-400 rounded flex items-center justify-center gap-3 py-3 disabled:opacity-30 disabled:cursor-not-allowed transition mt-3"
                        :disabled="!neighborByDirection.s"
                        @click="travel('s')"
                    >
                        <span class="text-amber-400 text-2xl">↓</span>
                        <TileIcon
                            v-if="neighborByDirection.s"
                            :type="neighborByDirection.s.type"
                            class="w-8 h-8"
                            :class="tileColor(neighborByDirection.s.type)"
                        />
                        <div class="text-left">
                            <div class="text-xs text-zinc-500 uppercase tracking-widest">South</div>
                            <div class="text-sm text-zinc-300">{{ neighborByDirection.s?.type ?? '— edge —' }}</div>
                        </div>
                    </button>

                    <div class="mt-4 text-zinc-500 text-xs text-center">
                        Tiles discovered: {{ state.discovered_count }}
                    </div>
                </div>

                <!-- Debug panel — keep temporarily for diagnostic -->
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
