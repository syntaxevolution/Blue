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
    hard_cap: number;
    base_coords: { x: number; y: number };
    owns_atlas: boolean;
    owns_attack_log: boolean;
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
    unlocks?: string[];
    [key: string]: unknown;
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
    can_purchase: boolean;
    block_reason: string | null;
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
    daily_count: number;
    daily_limit: number;
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
    owner_username: string | null;
    owner_immune: boolean;
    spy_decay_hours: number;
    raid_cooldown_hours: number;
    has_active_spy: boolean;
    latest_spy_at: string | null;
    last_attack_at: string | null;
    spy_move_cost: number;
    attack_move_cost: number;
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
const purchaseError = computed(() => errors.value.purchase ?? null);
const spyError = computed(() => errors.value.spy ?? null);
const attackError = computed(() => errors.value.attack ?? null);
const flash = computed(() => (page.props.flash as Record<string, string>) ?? {});
const drillResult = computed(() => flash.value.drill_result ?? null);
const purchaseResult = computed(() => flash.value.purchase_result ?? null);
const spyResult = computed(() => flash.value.spy_result ?? null);
const attackResult = computed(() => flash.value.attack_result ?? null);

const neighborByDirection = computed<Record<string, Neighbor | null>>(() => {
    const map: Record<string, Neighbor | null> = { n: null, s: null, e: null, w: null };
    for (const n of props.state.neighbors) {
        if (n.direction) map[n.direction] = n;
    }
    return map;
});

function travel(direction: 'n' | 's' | 'e' | 'w') {
    router.post(route('map.move'), { direction }, { preserveScroll: true, preserveState: false });
}

function drill(cell: DrillCell) {
    if (cell.drilled) return;
    if (dailyDrillLimitReached.value) return;
    router.post(route('map.drill'), { grid_x: cell.grid_x, grid_y: cell.grid_y }, { preserveScroll: true, preserveState: false });
}

function buy(item: ShopItem) {
    if (!item.can_purchase) return;
    router.post(route('map.purchase'), { item_key: item.key }, { preserveScroll: true, preserveState: false });
}

function spy() {
    router.post(route('map.spy'), {}, { preserveScroll: true, preserveState: false });
}

function attack() {
    router.post(route('map.attack'), {}, { preserveScroll: true, preserveState: false });
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

// Guard: treat a zero/missing daily_limit as "no limit configured" and
// don't erroneously mark the field as fully exhausted.
const dailyDrillLimitReached = computed(() => {
    if (props.state.tile_detail?.kind !== 'oil_field') return false;
    const { daily_count, daily_limit } = props.state.tile_detail;
    if (!daily_limit || daily_limit <= 0) return false;
    return daily_count >= daily_limit;
});

function drillCellClass(cell: DrillCell | undefined): string {
    if (!cell) return 'bg-zinc-800 border-zinc-700';
    if (cell.drilled) {
        return 'bg-zinc-950 border-zinc-800 text-zinc-700 cursor-not-allowed';
    }
    if (dailyDrillLimitReached.value) {
        return 'bg-zinc-900 border-zinc-800 text-zinc-700 cursor-not-allowed';
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

// Attack button availability for enemy bases
const canAttackNow = computed(() => {
    if (props.state.tile_detail?.kind !== 'enemy_base') return false;
    const d = props.state.tile_detail;
    if (d.owner_immune) return false;
    if (!d.has_active_spy) return false;
    if (d.last_attack_at) return false; // still in cooldown
    return true;
});
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
                <!-- Top resource bar (with home base coords) -->
                <div class="bg-zinc-900 border border-zinc-800 rounded-lg p-4 grid grid-cols-2 md:grid-cols-5 gap-4 text-sm font-mono">
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
                    <div>
                        <div class="text-zinc-500 text-xs uppercase">Home Base</div>
                        <div class="text-emerald-400 text-lg">
                            ({{ state.player.base_coords.x }}, {{ state.player.base_coords.y }})
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
                <div v-if="immunityActive" class="bg-amber-950/50 border border-amber-700/50 rounded-lg p-3 text-amber-300 text-sm font-mono">
                    New player immunity active until {{ state.player.immunity_expires_at }} — you cannot be attacked.
                </div>

                <!-- Flash messages -->
                <div v-if="drillResult" class="bg-emerald-950/50 border border-emerald-700/50 rounded-lg p-3 text-emerald-300 text-sm font-mono">{{ drillResult }}</div>
                <div v-if="purchaseResult" class="bg-emerald-950/50 border border-emerald-700/50 rounded-lg p-3 text-emerald-300 text-sm font-mono">{{ purchaseResult }}</div>
                <div v-if="spyResult" class="bg-emerald-950/50 border border-emerald-700/50 rounded-lg p-3 text-emerald-300 text-sm font-mono">{{ spyResult }}</div>
                <div v-if="attackResult" class="bg-emerald-950/50 border border-emerald-700/50 rounded-lg p-3 text-emerald-300 text-sm font-mono">{{ attackResult }}</div>
                <div v-if="travelError" class="bg-rose-950/50 border border-rose-700/50 rounded-lg p-3 text-rose-300 text-sm font-mono">{{ travelError }}</div>
                <div v-if="drillError" class="bg-rose-950/50 border border-rose-700/50 rounded-lg p-3 text-rose-300 text-sm font-mono">{{ drillError }}</div>
                <div v-if="purchaseError" class="bg-rose-950/50 border border-rose-700/50 rounded-lg p-3 text-rose-300 text-sm font-mono">{{ purchaseError }}</div>
                <div v-if="spyError" class="bg-rose-950/50 border border-rose-700/50 rounded-lg p-3 text-rose-300 text-sm font-mono">{{ spyError }}</div>
                <div v-if="attackError" class="bg-rose-950/50 border border-rose-700/50 rounded-lg p-3 text-rose-300 text-sm font-mono">{{ attackError }}</div>

                <!-- MAIN MAP PANEL -->
                <div class="bg-zinc-900 border-2 border-amber-500/40 rounded-lg p-4 md:p-6 font-mono shadow-xl shadow-amber-900/10">
                    <!-- N button on top -->
                    <button
                        type="button"
                        class="w-full bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 hover:border-amber-400 rounded flex items-center justify-center gap-3 py-3 disabled:opacity-30 disabled:cursor-not-allowed transition mb-3"
                        :disabled="!neighborByDirection.n"
                        @click="travel('n')"
                    >
                        <span class="text-amber-400 text-2xl">↑</span>
                        <TileIcon v-if="neighborByDirection.n" :type="neighborByDirection.n.type" class="w-8 h-8" :class="tileColor(neighborByDirection.n.type)" />
                        <div class="text-left">
                            <div class="text-xs text-zinc-500 uppercase tracking-widest">North</div>
                            <div class="text-sm text-zinc-300">{{ neighborByDirection.n?.type ?? '— edge —' }}</div>
                        </div>
                    </button>

                    <div class="flex items-stretch gap-3">
                        <!-- W button -->
                        <button
                            type="button"
                            class="w-20 md:w-28 shrink-0 bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 hover:border-amber-400 rounded flex flex-col items-center justify-center gap-2 px-2 py-4 disabled:opacity-30 disabled:cursor-not-allowed transition"
                            :disabled="!neighborByDirection.w"
                            @click="travel('w')"
                        >
                            <span class="text-amber-400 text-2xl">←</span>
                            <TileIcon v-if="neighborByDirection.w" :type="neighborByDirection.w.type" class="w-8 h-8" :class="tileColor(neighborByDirection.w.type)" />
                            <div class="text-xs text-zinc-500 uppercase tracking-widest">West</div>
                            <div class="text-xs text-zinc-300 break-words text-center">{{ neighborByDirection.w?.type ?? '— edge —' }}</div>
                        </button>

                        <!-- CENTER -->
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
                            <div class="text-zinc-100 text-2xl font-bold mb-1">{{ tileLabel(state.current_tile) }}</div>
                            <div v-if="state.current_tile.flavor_text" class="text-zinc-400 italic text-sm mb-4">
                                {{ state.current_tile.flavor_text }}
                            </div>

                            <!-- Interaction panel -->
                            <div class="w-full mt-4">
                                <!-- Oil field -->
                                <div v-if="state.tile_detail?.kind === 'oil_field'">
                                    <div class="text-zinc-500 text-xs uppercase mb-2 tracking-widest">Drill grid — 2 moves per cell</div>
                                    <div class="mb-3 text-sm font-mono">
                                        <span :class="dailyDrillLimitReached ? 'text-rose-400' : 'text-amber-400'">
                                            Drilled today: {{ state.tile_detail.daily_count }}/{{ state.tile_detail.daily_limit || 5 }}
                                        </span>
                                        <span v-if="dailyDrillLimitReached" class="text-rose-400 ml-2">· limit reached, returns at midnight</span>
                                    </div>
                                    <div class="inline-block">
                                        <div v-for="y in [4, 3, 2, 1, 0]" :key="y" class="flex gap-1 mb-1 justify-center">
                                            <button
                                                v-for="x in [0, 1, 2, 3, 4]"
                                                :key="`${x}:${y}`"
                                                type="button"
                                                class="w-11 h-11 rounded border flex items-center justify-center text-lg transition"
                                                :class="drillCellClass(drillGridMap[`${x}:${y}`])"
                                                :disabled="drillGridMap[`${x}:${y}`]?.drilled || dailyDrillLimitReached"
                                                @click="drill(drillGridMap[`${x}:${y}`])"
                                                :title="`(${x}, ${y})`"
                                            >
                                                {{ drillCellLabel(drillGridMap[`${x}:${y}`]) }}
                                            </button>
                                        </div>
                                    </div>
                                    <div class="text-zinc-500 text-xs mt-3">? = undrilled · ✕ = depleted</div>
                                </div>

                                <!-- Post -->
                                <div v-else-if="state.tile_detail?.kind === 'post'" class="text-left">
                                    <div class="text-zinc-500 text-xs uppercase mb-3 tracking-widest text-center">{{ state.tile_detail.post_type }} post · inventory</div>
                                    <div v-if="state.tile_detail.items.length === 0" class="text-zinc-500 text-sm italic text-center">No items for sale at this post type yet.</div>
                                    <div v-else class="space-y-2">
                                        <div
                                            v-for="item in state.tile_detail.items"
                                            :key="item.key"
                                            class="bg-zinc-900 border border-zinc-800 rounded p-3 flex items-start justify-between gap-3"
                                        >
                                            <div class="flex-1 min-w-0">
                                                <div class="text-zinc-100 text-sm font-bold">{{ item.name }}</div>
                                                <div v-if="item.description" class="text-zinc-500 text-xs mt-0.5">{{ item.description }}</div>
                                                <div class="text-emerald-400 text-xs mt-1">
                                                    <span v-for="(effect, idx) in formatEffects(item.effects)" :key="idx" class="mr-2">{{ effect }}</span>
                                                </div>
                                            </div>
                                            <div class="shrink-0 flex flex-col items-end gap-2">
                                                <div class="text-amber-400 text-xs font-bold whitespace-nowrap">{{ formatPrice(item) }}</div>
                                                <button
                                                    type="button"
                                                    class="bg-amber-500 hover:bg-amber-400 text-zinc-950 text-xs font-bold uppercase tracking-wider px-3 py-1 rounded transition disabled:opacity-30 disabled:cursor-not-allowed"
                                                    :disabled="!item.can_purchase"
                                                    @click="buy(item)"
                                                >Buy</button>
                                                <div v-if="!item.can_afford" class="text-rose-400 text-[10px] uppercase tracking-widest">Can't afford</div>
                                                <div v-else-if="item.block_reason" class="text-rose-400 text-[10px] uppercase tracking-widest">{{ item.block_reason }}</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Own base -->
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

                                <!-- ENEMY BASE with spy/attack -->
                                <div v-else-if="state.tile_detail?.kind === 'enemy_base'" class="rounded border border-rose-900/60 bg-rose-950/20 p-4 text-left">
                                    <div class="text-rose-400 text-xs uppercase tracking-widest mb-2">Enemy base</div>
                                    <div class="text-2xl font-bold text-zinc-100 mb-1">
                                        {{ state.tile_detail.owner_username ?? 'Unknown' }}
                                    </div>
                                    <div
                                        v-if="state.tile_detail.owner_immune"
                                        class="text-amber-400 text-sm mb-3 italic"
                                    >
                                        Under new-player immunity — cannot be spied on or attacked.
                                    </div>
                                    <div
                                        v-else-if="!state.tile_detail.has_active_spy"
                                        class="text-zinc-400 text-sm mb-3"
                                    >
                                        No active reconnaissance. You need a successful spy within {{ state.tile_detail.spy_decay_hours }}h before you can attack.
                                    </div>
                                    <div
                                        v-else-if="state.tile_detail.last_attack_at"
                                        class="text-zinc-400 text-sm mb-3"
                                    >
                                        You raided this base recently. Cooldown: {{ state.tile_detail.raid_cooldown_hours }}h between raids on the same target.
                                    </div>
                                    <div v-else class="text-emerald-400 text-sm mb-3">
                                        Spy intel is fresh. You can raid.
                                    </div>

                                    <div class="flex gap-2 mt-4">
                                        <button
                                            type="button"
                                            class="flex-1 bg-violet-800 hover:bg-violet-700 border border-violet-600 text-zinc-100 text-sm font-bold uppercase tracking-wider px-4 py-3 rounded transition disabled:opacity-30 disabled:cursor-not-allowed"
                                            :disabled="state.tile_detail.owner_immune"
                                            @click="spy"
                                        >
                                            Spy ({{ state.tile_detail.spy_move_cost }} moves)
                                        </button>
                                        <button
                                            type="button"
                                            class="flex-1 bg-rose-800 hover:bg-rose-700 border border-rose-600 text-zinc-100 text-sm font-bold uppercase tracking-wider px-4 py-3 rounded transition disabled:opacity-30 disabled:cursor-not-allowed"
                                            :disabled="!canAttackNow"
                                            @click="attack"
                                        >
                                            Attack ({{ state.tile_detail.attack_move_cost }} moves)
                                        </button>
                                    </div>
                                </div>

                                <!-- Wasteland / landmark / ruin -->
                                <div v-else class="text-zinc-600 text-sm italic">Nothing to do here but keep walking.</div>
                            </div>
                        </div>

                        <!-- E button -->
                        <button
                            type="button"
                            class="w-20 md:w-28 shrink-0 bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 hover:border-amber-400 rounded flex flex-col items-center justify-center gap-2 px-2 py-4 disabled:opacity-30 disabled:cursor-not-allowed transition"
                            :disabled="!neighborByDirection.e"
                            @click="travel('e')"
                        >
                            <span class="text-amber-400 text-2xl">→</span>
                            <TileIcon v-if="neighborByDirection.e" :type="neighborByDirection.e.type" class="w-8 h-8" :class="tileColor(neighborByDirection.e.type)" />
                            <div class="text-xs text-zinc-500 uppercase tracking-widest">East</div>
                            <div class="text-xs text-zinc-300 break-words text-center">{{ neighborByDirection.e?.type ?? '— edge —' }}</div>
                        </button>
                    </div>

                    <!-- S button -->
                    <button
                        type="button"
                        class="w-full bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 hover:border-amber-400 rounded flex items-center justify-center gap-3 py-3 disabled:opacity-30 disabled:cursor-not-allowed transition mt-3"
                        :disabled="!neighborByDirection.s"
                        @click="travel('s')"
                    >
                        <span class="text-amber-400 text-2xl">↓</span>
                        <TileIcon v-if="neighborByDirection.s" :type="neighborByDirection.s.type" class="w-8 h-8" :class="tileColor(neighborByDirection.s.type)" />
                        <div class="text-left">
                            <div class="text-xs text-zinc-500 uppercase tracking-widest">South</div>
                            <div class="text-sm text-zinc-300">{{ neighborByDirection.s?.type ?? '— edge —' }}</div>
                        </div>
                    </button>

                    <div class="mt-4 text-zinc-500 text-xs text-center">Tiles discovered: {{ state.discovered_count }}</div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
