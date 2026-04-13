<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TileIcon from '@/Components/TileIcon.vue';
import TransportSwitcher from '@/Components/TransportSwitcher.vue';
import TeleportModal from '@/Components/TeleportModal.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useToolbox } from '@/Composables/useToolbox';

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
    active_transport?: string;
    owned_transports?: string[];
    owns_teleporter?: boolean;
    mdn_id?: number | null;
    mdn_tag?: string | null;
    mdn_name?: string | null;
    owns_sabotage_scanner?: boolean;
}

interface TransportCatalogEntry {
    key: string;
    cost_barrels: number;
    spaces: number;
    fuel: number;
    flags: string[];
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
    grant_moves?: number | boolean;
    bank_cap_bonus?: number;
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
    category: string;
    category_order: number;
    owned_quantity: number;
    can_afford: boolean;
    can_purchase: boolean;
    block_reason: string | null;
}

interface DrillCellSabotage {
    device_key: string;
    // true when the viewing player planted this trap — used to show
    // a distinct "your own trap" style and to block accidental drill.
    own: boolean;
}

interface DrillCell {
    grid_x: number;
    grid_y: number;
    quality: string;
    drilled: boolean;
    // Present when the server says this cell has an active sabotage
    // visible to the viewer: either the viewer is the planter (spec #4)
    // or the viewer owns a Deep Scanner (spec #3). Null otherwise —
    // an invisible trap still exists on the server, the driller just
    // can't see it.
    sabotage: DrillCellSabotage | null;
}

interface OilFieldDetail {
    kind: 'oil_field';
    grid: DrillCell[];
    daily_count: number;
    daily_limit: number;
    refill_at: string | null;
    fully_depleted: boolean;
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
    owner_mdn_tag: string | null;
    owner_mdn_name: string | null;
    same_mdn_blocked: boolean;
    spy_decay_hours: number;
    raid_cooldown_hours: number;
    has_active_spy: boolean;
    latest_spy_at: string | null;
    last_attack_at: string | null;
    spy_move_cost: number;
    attack_move_cost: number;
}

interface CasinoDetail {
    kind: 'casino';
    name: string;
    entry_fee_barrels: number;
    casino_enabled: boolean;
    has_active_session: boolean;
    session_expires_at: string | null;
}

interface WastelandOccupant {
    player_id: number;
    username: string;
    mdn_tag: string | null;
    mdn_name: string | null;
    is_bot: boolean;
    is_immune: boolean;
    can_fight: boolean;
    block_reason: string | null;
    block_reason_label: string;
}

interface WastelandDetail {
    kind: 'wasteland';
    occupants: WastelandOccupant[];
    cooldown_hours: number;
    move_cost: number;
    max_oil_loot_pct: number;
}

type TileDetail = OilFieldDetail | PostDetail | OwnBaseDetail | EnemyBaseDetail | CasinoDetail | WastelandDetail | null;

interface MapState {
    player: PlayerState;
    current_tile: TileInfo;
    tile_detail: TileDetail;
    neighbors: Neighbor[];
    discovered_count: number;
    bank_cap: number;
    transport_catalog?: Record<string, TransportCatalogEntry>;
    immunity_hours?: number;
}

const props = defineProps<{
    state: MapState;
}>();

const showTeleportModal = ref(false);

const page = usePage();
// Teleport cost comes from Inertia shared props so it stays in sync
// with live admin tuning via the Filament game_settings panel.
const teleportCost = computed<number>(
    () => Number((page.props.game as { teleport_cost_barrels?: number } | undefined)?.teleport_cost_barrels ?? 5000),
);
const errors = computed(() => (page.props.errors as Record<string, string>) ?? {});
const travelError = computed(() => errors.value.travel ?? null);
const drillError = computed(() => errors.value.drill ?? null);
const purchaseError = computed(() => errors.value.purchase ?? null);
const spyError = computed(() => errors.value.spy ?? null);
const attackError = computed(() => errors.value.attack ?? null);
const tileCombatError = computed(() => errors.value.tile_combat ?? null);
const placeError = computed(() => errors.value.place_device ?? null);
const flash = computed(() => (page.props.flash as Record<string, unknown>) ?? {});
interface DrillResult {
    barrels: number;
    quality: string;
    grid_x: number;
    grid_y: number;
    drill_broke: boolean;
    broken_item_key: string | null;
    sabotage_outcome: string | null;
    sabotage_device_key: string | null;
    siphoned_barrels: number;
}
const drillResult = computed<DrillResult | null>(
    () => (flash.value.drill_result as DrillResult | undefined) ?? null,
);
const drillResultText = computed<string | null>(() => {
    const r = drillResult.value;
    if (!r) return null;
    // Sabotage outcomes take priority over the ordinary drill message —
    // they describe what actually happened to the player. Where the
    // normal random break roll ALSO fired on top of a sabotage outcome
    // that didn't itself break the rig (fizzle/siphoned_tier_one), we
    // still need to tell the player to go to a Tech post, so append
    // the break instruction below.
    if (r.sabotage_outcome) {
        let msg: string;
        switch (r.sabotage_outcome) {
            case 'drill_broken_and_siphoned':
                msg = `A planted device triggered: your rig was wrecked and ${r.siphoned_barrels} barrels were siphoned straight out of your stash. Head to a Tech post to replace your rig.`;
                break;
            case 'drill_broken':
                msg = 'A planted device triggered: your rig was wrecked. Head to a Tech post to replace it.';
                break;
            case 'siphoned_tier_one':
                msg = `A siphon charge triggered: your starter rig held together, but ${r.siphoned_barrels} barrels vanished down the pipe.`;
                break;
            case 'fizzled_tier_one':
                msg = 'A booby-trap triggered on your drill, but the starter rig shrugged it off. You still lost the move.';
                break;
            case 'fizzled_immune':
                msg = 'A planted device triggered on you — but you got lucky this time. New-player immunity held.';
                break;
            case 'detected':
                msg = 'A Tripwire Ward saved your rig from a planted device. One ward consumed.';
                break;
            default:
                msg = 'A planted device triggered on your drill.';
                break;
        }
        // Catch the case where the normal random break roll also
        // wrecked the rig AFTER a non-breaking sabotage outcome.
        // (drill_broken* already cover break instructions.)
        const sabotageBroke = r.sabotage_outcome === 'drill_broken' || r.sabotage_outcome === 'drill_broken_and_siphoned';
        if (r.drill_broke && !sabotageBroke) {
            msg += ' Your drill also broke from wear — head to a Tech post to repair or replace it.';
        }
        return msg;
    }
    const core = `Drilled a ${r.quality} point: +${r.barrels} barrels.`;
    if (r.drill_broke) {
        return `${core} Your drill broke — head to a Tech post to repair or replace it.`;
    }
    return core;
});

interface PlaceResult {
    device_key: string;
    device_name: string;
    grid_x: number;
    grid_y: number;
    remaining_quantity: number;
}
const placeResult = computed<PlaceResult | null>(
    () => (flash.value.place_result as PlaceResult | undefined) ?? null,
);
const placeResultText = computed<string | null>(() => {
    const r = placeResult.value;
    if (!r) return null;
    const name = r.device_name || r.device_key;
    return `Planted ${name} at (${r.grid_x}, ${r.grid_y}). ${r.remaining_quantity} remaining in your toolbox.`;
});
const purchaseResult = computed(() => (flash.value.purchase_result as string | undefined) ?? null);
const spyResult = computed(() => (flash.value.spy_result as string | undefined) ?? null);
const attackResult = computed(() => (flash.value.attack_result as string | undefined) ?? null);
const tileCombatResult = computed(() => (flash.value.tile_combat_result as string | undefined) ?? null);

const fightConfirmTarget = ref<WastelandOccupant | null>(null);
const fightInFlight = ref(false);

// Narrowed view of tile_detail for the fight modal — returns the
// wasteland payload when the player is actually on a wasteland tile,
// null otherwise. Used instead of inline `state.tile_detail.kind === ...`
// checks in the template so we never fall back to hardcoded balance
// values (project rule: no hardcoded balance numbers).
const wastelandDetailForModal = computed<WastelandDetail | null>(() => {
    const detail = props.state.tile_detail;
    if (detail && detail.kind === 'wasteland') {
        return detail;
    }
    return null;
});

function openFightConfirm(occupant: WastelandOccupant) {
    if (!occupant.can_fight) return;
    fightConfirmTarget.value = occupant;
}

function cancelFight() {
    fightConfirmTarget.value = null;
}

function confirmFight() {
    const target = fightConfirmTarget.value;
    if (!target) return;
    fightInFlight.value = true;
    router.post(
        route('map.tile_combat'),
        { defender_player_id: target.player_id },
        {
            preserveScroll: true,
            preserveState: true,
            // Close the modal only when the engagement SUCCEEDED.
            // On 422 (cooldown race, target immunity flipped, target
            // walked away) we keep the modal mounted so the player
            // sees the inline error instead of it flashing in the
            // top-of-page banner after the modal has already vanished.
            onSuccess: () => {
                fightConfirmTarget.value = null;
            },
            onFinish: () => {
                fightInFlight.value = false;
            },
        },
    );
}

const neighborByDirection = computed<Record<string, Neighbor | null>>(() => {
    const map: Record<string, Neighbor | null> = { n: null, s: null, e: null, w: null };
    for (const n of props.state.neighbors) {
        if (n.direction) map[n.direction] = n;
    }
    return map;
});

// Current transport shape (spaces + fuel) so the direction buttons can
// show "×50" and "5 fuel" badges for airplane/helicopter, making the
// magnitude of each press obvious before the player clicks.
const activeTransportInfo = computed(() => {
    const key = props.state.player.active_transport ?? 'walking';
    const cfg = props.state.transport_catalog?.[key];
    return {
        key,
        spaces: cfg?.spaces ?? 1,
        fuel: cfg?.fuel ?? 0,
    };
});

function travel(direction: 'n' | 's' | 'e' | 'w') {
    router.post(route('map.move'), { direction }, { preserveScroll: true, preserveState: true });
}

const toolbox = useToolbox();

function drill(cell: DrillCell) {
    // Placement mode hijacks clicks on the drill grid: the same cell
    // the player would normally drill becomes their plant target.
    if (toolbox.state.placementActive && toolbox.state.placementDeviceKey) {
        // Cannot plant on an already-depleted cell or one that already
        // has a visible trap (own or scanner-revealed).
        if (cell.drilled) return;
        if (cell.sabotage !== null) return;
        router.post(
            route('map.place_device'),
            {
                grid_x: cell.grid_x,
                grid_y: cell.grid_y,
                item_key: toolbox.state.placementDeviceKey,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => toolbox.exit(),
            },
        );
        return;
    }

    if (cell.drilled) return;
    if (dailyDrillLimitReached.value) return;
    // Scanner-visible trap planted by someone else → block. Own traps
    // also block to protect the planter from fat-fingering into their
    // own mine.
    if (cell.sabotage !== null) return;
    router.post(route('map.drill'), { grid_x: cell.grid_x, grid_y: cell.grid_y }, { preserveScroll: true, preserveState: true });
}

// Esc cancels placement mode. Registered globally while Map.vue is
// mounted so the dock's "cancel" button and the keyboard shortcut
// both point at the same composable method.
function handleKeydown(e: KeyboardEvent): void {
    if (e.key === 'Escape' && toolbox.state.placementActive) {
        toolbox.exit();
    }
}
onMounted(() => window.addEventListener('keydown', handleKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', handleKeydown));

// Auto-cancel placement mode whenever the player leaves the current
// oil field — travel, teleport, page navigation, anything that moves
// the current_tile away. Without this, the amber placement banner
// would stay stuck on-screen even though the drill grid has
// disappeared, and the composable's singleton state would outlive
// the oil-field context that made the toolbox "Place" button clickable.
watch(
    () => props.state.current_tile.type,
    (newType) => {
        if (newType !== 'oil_field' && toolbox.state.placementActive) {
            toolbox.exit();
        }
        // Auto-cancel the fight confirmation modal if the player
        // travels away from the wasteland tile while the modal is
        // open — the target they selected is no longer reachable,
        // and the modal would display stale occupant info.
        if (newType !== 'wasteland' && fightConfirmTarget.value !== null) {
            fightConfirmTarget.value = null;
        }
    },
);

function buy(item: ShopItem) {
    if (!item.can_purchase) return;
    router.post(route('map.purchase'), { item_key: item.key }, { preserveScroll: true, preserveState: true });
}

function spy() {
    router.post(route('map.spy'), {}, { preserveScroll: true, preserveState: true });
}

function attack() {
    router.post(route('map.attack'), {}, { preserveScroll: true, preserveState: true });
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
        casino: 'text-yellow-400',
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

// Human-friendly "in 4h 12m" / "any moment now" copy for the field
// refill banner. Computed off refill_at which the backend sends as an
// ISO timestamp so the server is the source of truth.
const refillCountdown = computed<string>(() => {
    if (props.state.tile_detail?.kind !== 'oil_field') return '';
    const iso = props.state.tile_detail.refill_at;
    if (!iso) return '';
    const ts = new Date(iso).getTime();
    if (Number.isNaN(ts)) return '';
    const diffMs = ts - Date.now();
    if (diffMs <= 0) return 'any moment now';
    const totalMin = Math.round(diffMs / 60000);
    if (totalMin < 60) return `in ${totalMin}m`;
    const hours = Math.floor(totalMin / 60);
    const mins = totalMin % 60;
    return mins > 0 ? `in ${hours}h ${mins}m` : `in ${hours}h`;
});

function drillCellClass(cell: DrillCell | undefined): string {
    if (!cell) return 'bg-zinc-800 border-zinc-700';
    if (cell.drilled) {
        return 'bg-zinc-950 border-zinc-800 text-zinc-700 cursor-not-allowed';
    }
    // Visible traps take priority in the renderer.
    if (cell.sabotage !== null) {
        if (cell.sabotage.own) {
            // Your own trap — amber outline so you know it's there and
            // won't drill into it by accident.
            return 'bg-amber-950/60 border-amber-700/60 text-amber-400 cursor-not-allowed';
        }
        // Someone else's trap, revealed by Deep Scanner. Red hazard.
        return 'bg-rose-950/50 border-rose-800/70 text-rose-400 cursor-not-allowed';
    }
    // Placement mode: non-drilled cells glow amber as "plant here" targets.
    if (toolbox.state.placementActive) {
        return 'bg-zinc-800 border-amber-500/50 hover:border-amber-400 hover:bg-amber-950/40 text-amber-400 cursor-crosshair';
    }
    if (dailyDrillLimitReached.value) {
        return 'bg-zinc-900 border-zinc-800 text-zinc-700 cursor-not-allowed';
    }
    return 'bg-zinc-800 border-zinc-700 hover:border-amber-400 hover:bg-zinc-700 text-zinc-500 hover:text-amber-400 cursor-pointer';
}

function drillCellLabel(cell: DrillCell | undefined): string {
    if (!cell) return '·';
    if (cell.drilled) return '✕';
    if (cell.sabotage !== null) {
        return cell.sabotage.own ? '⚑' : '☠';
    }
    if (toolbox.state.placementActive) return '+';
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
    if (typeof effects.bank_cap_bonus === 'number' && effects.bank_cap_bonus !== 0) {
        parts.push(`+${effects.bank_cap_bonus} max moves`);
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

// Attack button availability for enemy bases.
// last_attack_at is only sent by the server when it's still inside the
// cooldown window, but if the player sits on the page across cooldown
// expiry without reloading, we compare the timestamp against the local
// clock so the button re-enables itself without needing a full reload.
const canAttackNow = computed(() => {
    if (props.state.tile_detail?.kind !== 'enemy_base') return false;
    const d = props.state.tile_detail;
    if (d.owner_immune) return false;
    if (!d.has_active_spy) return false;
    if (d.last_attack_at) {
        const cooldownMs = (d.raid_cooldown_hours ?? 12) * 3600 * 1000;
        const attackedAt = new Date(d.last_attack_at).getTime();
        if (!Number.isNaN(attackedAt) && Date.now() - attackedAt < cooldownMs) {
            return false;
        }
    }
    return true;
});
</script>

<template>
    <Head title="Map — Clash Wars" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-mono text-xl font-bold uppercase tracking-wider text-amber-400">
                Akzar — Map
            </h2>
        </template>

        <div class="py-4 sm:py-8">
            <div class="max-w-5xl mx-auto px-3 sm:px-6 lg:px-8 space-y-4 sm:space-y-6">
                <!-- Top resource bar (with home base coords) -->
                <div class="bg-zinc-900 border border-zinc-800 rounded-lg p-3 sm:p-4 grid grid-cols-2 md:grid-cols-5 gap-3 sm:gap-4 text-sm font-mono">
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
                <div class="bg-zinc-900 border border-zinc-800 rounded-lg p-3 sm:p-4 grid grid-cols-5 gap-2 sm:gap-4 text-sm font-mono">
                    <div class="text-center">
                        <div class="text-zinc-500 text-[10px] sm:text-xs uppercase">Strength</div>
                        <div class="text-rose-400 text-lg sm:text-xl">{{ state.player.strength }}</div>
                    </div>
                    <div class="text-center">
                        <div class="text-zinc-500 text-[10px] sm:text-xs uppercase">Fort</div>
                        <div class="text-emerald-400 text-lg sm:text-xl">{{ state.player.fortification }}</div>
                    </div>
                    <div class="text-center">
                        <div class="text-zinc-500 text-[10px] sm:text-xs uppercase">Stealth</div>
                        <div class="text-violet-400 text-lg sm:text-xl">{{ state.player.stealth }}</div>
                    </div>
                    <div class="text-center">
                        <div class="text-zinc-500 text-[10px] sm:text-xs uppercase">Security</div>
                        <div class="text-sky-400 text-lg sm:text-xl">{{ state.player.security }}</div>
                    </div>
                    <div class="text-center">
                        <div class="text-zinc-500 text-[10px] sm:text-xs uppercase">Drill</div>
                        <div class="text-amber-400 text-lg sm:text-xl">T{{ state.player.drill_tier }}</div>
                    </div>
                </div>

                <!-- Immunity banner -->
                <div v-if="immunityActive" class="bg-amber-950/50 border border-amber-700/50 rounded-lg p-3 text-amber-300 text-sm font-mono">
                    New player immunity active until {{ state.player.immunity_expires_at }} — you cannot be attacked.
                </div>

                <!-- Transport + Teleport bar -->
                <div class="flex flex-wrap items-center justify-between gap-3 bg-zinc-900 border border-zinc-800 rounded-lg px-4 py-3">
                    <TransportSwitcher
                        :active="state.player.active_transport ?? 'walking'"
                        :owned="state.player.owned_transports ?? ['walking']"
                        :catalog="state.transport_catalog ?? {}"
                    />
                    <button
                        v-if="state.player.owns_teleporter"
                        type="button"
                        class="rounded border border-violet-700 bg-violet-900/40 px-3 py-1 font-mono text-xs uppercase tracking-widest text-violet-300 hover:border-violet-400 hover:text-violet-200 transition"
                        @click="showTeleportModal = true"
                    >
                        ⚡ Teleport
                    </button>
                </div>

                <!-- Flash messages -->
                <div v-if="drillResultText" class="bg-emerald-950/50 border border-emerald-700/50 rounded-lg p-3 text-emerald-300 text-sm font-mono">{{ drillResultText }}</div>
                <div v-if="placeResultText" class="bg-amber-950/50 border border-amber-700/50 rounded-lg p-3 text-amber-300 text-sm font-mono">{{ placeResultText }}</div>
                <div v-if="purchaseResult" class="bg-emerald-950/50 border border-emerald-700/50 rounded-lg p-3 text-emerald-300 text-sm font-mono">{{ purchaseResult }}</div>
                <div v-if="spyResult" class="bg-emerald-950/50 border border-emerald-700/50 rounded-lg p-3 text-emerald-300 text-sm font-mono">{{ spyResult }}</div>
                <div v-if="attackResult" class="bg-emerald-950/50 border border-emerald-700/50 rounded-lg p-3 text-emerald-300 text-sm font-mono">{{ attackResult }}</div>
                <div v-if="tileCombatResult" class="bg-emerald-950/50 border border-emerald-700/50 rounded-lg p-3 text-emerald-300 text-sm font-mono">{{ tileCombatResult }}</div>
                <div v-if="travelError" class="bg-rose-950/50 border border-rose-700/50 rounded-lg p-3 text-rose-300 text-sm font-mono">{{ travelError }}</div>
                <div v-if="drillError" class="bg-rose-950/50 border border-rose-700/50 rounded-lg p-3 text-rose-300 text-sm font-mono">{{ drillError }}</div>
                <div v-if="placeError" class="bg-rose-950/50 border border-rose-700/50 rounded-lg p-3 text-rose-300 text-sm font-mono">{{ placeError }}</div>
                <div v-if="purchaseError" class="bg-rose-950/50 border border-rose-700/50 rounded-lg p-3 text-rose-300 text-sm font-mono">{{ purchaseError }}</div>
                <div v-if="spyError" class="bg-rose-950/50 border border-rose-700/50 rounded-lg p-3 text-rose-300 text-sm font-mono">{{ spyError }}</div>
                <div v-if="attackError" class="bg-rose-950/50 border border-rose-700/50 rounded-lg p-3 text-rose-300 text-sm font-mono">{{ attackError }}</div>
                <div v-if="tileCombatError" class="bg-rose-950/50 border border-rose-700/50 rounded-lg p-3 text-rose-300 text-sm font-mono">{{ tileCombatError }}</div>

                <!-- MAIN MAP PANEL -->
                <div class="bg-zinc-900 border-2 border-amber-500/40 rounded-lg p-3 sm:p-4 md:p-6 font-mono shadow-xl shadow-amber-900/10">
                    <!-- Compact mobile direction pad: 4 equal buttons stacked above the tile info.
                         Hidden on sm+ where the cross layout (N/W/center/E/S) takes over. -->
                    <div class="mb-3 grid grid-cols-4 gap-2 sm:hidden">
                        <button
                            v-for="dir in (['w', 'n', 's', 'e'] as const)"
                            :key="dir"
                            type="button"
                            class="tap-target flex h-14 flex-col items-center justify-center gap-0.5 rounded border border-zinc-700 bg-zinc-800 px-1 py-1 text-amber-400 transition active:border-amber-400 active:bg-zinc-700 disabled:opacity-30 disabled:cursor-not-allowed"
                            :disabled="!neighborByDirection[dir]"
                            @click="travel(dir)"
                        >
                            <span class="text-xl leading-none">
                                {{ { w: '←', n: '↑', s: '↓', e: '→' }[dir] }}
                            </span>
                            <span class="text-[10px] uppercase tracking-widest text-zinc-400">
                                {{ { w: 'West', n: 'North', s: 'South', e: 'East' }[dir] }}
                            </span>
                        </button>
                    </div>

                    <!-- N button on top (desktop cross layout) -->
                    <button
                        type="button"
                        class="hidden w-full bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 hover:border-amber-400 rounded sm:flex items-center justify-center gap-2 sm:gap-3 py-2 sm:py-3 disabled:opacity-30 disabled:cursor-not-allowed transition mb-3"
                        :disabled="!neighborByDirection.n"
                        @click="travel('n')"
                    >
                        <span class="text-amber-400 text-xl sm:text-2xl">↑</span>
                        <TileIcon v-if="neighborByDirection.n" :type="neighborByDirection.n.type" class="w-6 h-6 sm:w-8 sm:h-8" :class="tileColor(neighborByDirection.n.type)" />
                        <div class="text-left min-w-0">
                            <div class="text-[10px] sm:text-xs text-zinc-500 uppercase tracking-widest flex items-center gap-1.5">
                                <span>North</span>
                                <span v-if="activeTransportInfo.spaces > 1" class="text-amber-400 font-bold">×{{ activeTransportInfo.spaces }}</span>
                                <span v-if="activeTransportInfo.fuel > 0" class="text-rose-400">· {{ activeTransportInfo.fuel }} fuel</span>
                            </div>
                            <div class="text-xs sm:text-sm text-zinc-300 break-words">
                                <template v-if="neighborByDirection.n">{{ neighborByDirection.n.type }}</template>
                                <template v-else-if="activeTransportInfo.spaces > 1">— past the frontier —</template>
                                <template v-else>— edge —</template>
                            </div>
                        </div>
                    </button>

                    <div class="flex items-stretch gap-2 sm:gap-3">
                        <!-- W button (desktop cross layout) -->
                        <button
                            type="button"
                            class="hidden sm:w-20 md:w-28 shrink-0 bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 hover:border-amber-400 rounded sm:flex flex-col items-center justify-center gap-1 sm:gap-2 px-1 sm:px-2 py-3 sm:py-4 disabled:opacity-30 disabled:cursor-not-allowed transition"
                            :disabled="!neighborByDirection.w"
                            @click="travel('w')"
                        >
                            <span class="text-amber-400 text-xl sm:text-2xl">←</span>
                            <TileIcon v-if="neighborByDirection.w" :type="neighborByDirection.w.type" class="w-6 h-6 sm:w-8 sm:h-8" :class="tileColor(neighborByDirection.w.type)" />
                            <div class="text-[10px] sm:text-xs text-zinc-500 uppercase tracking-widest flex items-center gap-1">
                                <span>West</span>
                                <span v-if="activeTransportInfo.spaces > 1" class="text-amber-400 font-bold">×{{ activeTransportInfo.spaces }}</span>
                            </div>
                            <div class="hidden sm:block text-xs text-zinc-300 break-words text-center">
                                <template v-if="neighborByDirection.w">{{ neighborByDirection.w.type }}</template>
                                <template v-else-if="activeTransportInfo.spaces > 1">— past frontier —</template>
                                <template v-else>— edge —</template>
                            </div>
                        </button>

                        <!-- CENTER -->
                        <div class="flex-1 min-w-0 bg-zinc-950/60 border border-amber-500/20 rounded-lg p-3 sm:p-5 flex flex-col items-center text-center">
                            <div class="text-amber-400 text-xs uppercase tracking-[0.3em] mb-3 flex items-center gap-2">
                                <span class="inline-block h-2 w-2 rounded-full bg-amber-400 animate-pulse"></span>
                                You are here
                            </div>

                            <div
                                class="rounded-lg border border-amber-500/30 bg-amber-500/5 p-3 sm:p-4 mb-3"
                                :class="tileColor(state.current_tile.is_own_base ? 'base' : state.current_tile.type)"
                            >
                                <TileIcon
                                    :type="state.current_tile.is_own_base ? 'base' : state.current_tile.type"
                                    class="w-14 h-14 sm:w-20 sm:h-20"
                                />
                            </div>

                            <div class="text-amber-400 text-xs sm:text-sm mb-1 break-words">
                                ({{ state.current_tile.x }}, {{ state.current_tile.y }})
                                <span class="text-zinc-600 text-[10px] sm:text-xs ml-1">· tile #{{ state.current_tile.id }}</span>
                            </div>
                            <div class="text-zinc-100 text-xl sm:text-2xl font-bold mb-1 break-words">{{ tileLabel(state.current_tile) }}</div>
                            <div v-if="state.current_tile.flavor_text" class="text-zinc-400 italic text-sm mb-4">
                                {{ state.current_tile.flavor_text }}
                            </div>

                            <!-- Interaction panel -->
                            <div class="w-full mt-4">
                                <!-- Oil field -->
                                <div v-if="state.tile_detail?.kind === 'oil_field'">
                                    <div class="text-zinc-500 text-xs uppercase mb-2 tracking-widest">
                                        {{ toolbox.state.placementActive ? `Placement — click a cell to plant ${toolbox.state.placementDeviceName}` : 'Drill grid — 2 moves per cell' }}
                                    </div>
                                    <div class="mb-3 text-sm font-mono">
                                        <span :class="dailyDrillLimitReached ? 'text-rose-400' : 'text-amber-400'">
                                            Drilled today: {{ state.tile_detail.daily_count }}/{{ state.tile_detail.daily_limit || 5 }}
                                        </span>
                                        <span v-if="dailyDrillLimitReached" class="text-rose-400 ml-2">· your daily limit on this field resets tomorrow</span>
                                    </div>
                                    <div v-if="state.tile_detail.fully_depleted" class="mb-3 rounded border border-amber-700/40 bg-amber-950/30 px-3 py-2 text-xs font-mono text-amber-200">
                                        Field fully depleted — refills {{ refillCountdown }}.
                                    </div>
                                    <div class="mx-auto inline-block max-w-full">
                                        <div v-for="y in [4, 3, 2, 1, 0]" :key="y" class="flex gap-1 mb-1 justify-center">
                                            <button
                                                v-for="x in [0, 1, 2, 3, 4]"
                                                :key="`${x}:${y}`"
                                                type="button"
                                                class="relative w-11 h-11 sm:w-12 sm:h-12 rounded border flex items-center justify-center text-base sm:text-lg transition"
                                                :class="drillCellClass(drillGridMap[`${x}:${y}`])"
                                                :disabled="
                                                    !drillGridMap[`${x}:${y}`]
                                                    || drillGridMap[`${x}:${y}`]?.drilled
                                                    || !!drillGridMap[`${x}:${y}`]?.sabotage
                                                    || (!toolbox.state.placementActive && dailyDrillLimitReached)
                                                "
                                                @click="drill(drillGridMap[`${x}:${y}`])"
                                                :title="drillGridMap[`${x}:${y}`]?.sabotage
                                                    ? (drillGridMap[`${x}:${y}`]?.sabotage?.own ? `(${x}, ${y}) — your planted device` : `(${x}, ${y}) — RIGGED (scanner reveal)`)
                                                    : `(${x}, ${y})`"
                                            >
                                                {{ drillCellLabel(drillGridMap[`${x}:${y}`]) }}
                                                <span
                                                    v-if="drillResult && drillResult.grid_x === x && drillResult.grid_y === y"
                                                    :key="`pop-${x}-${y}-${drillResult.barrels}`"
                                                    class="drill-popup pointer-events-none absolute left-1/2 -top-1 -translate-x-1/2 font-mono font-bold text-sm sm:text-base select-none whitespace-nowrap"
                                                    :class="drillResult.barrels > 0 ? 'text-emerald-400' : 'text-zinc-500'"
                                                >
                                                    {{ drillResult.barrels > 0 ? `+${drillResult.barrels}` : 'dry' }}
                                                </span>
                                                <span
                                                    v-if="drillResult && drillResult.drill_broke && drillResult.grid_x === x && drillResult.grid_y === y"
                                                    :key="`break-${x}-${y}-${drillResult.broken_item_key}`"
                                                    class="drill-popup-break pointer-events-none absolute left-1/2 -bottom-1 -translate-x-1/2 font-mono font-black text-[10px] sm:text-xs select-none whitespace-nowrap text-rose-400"
                                                >
                                                    BREAK
                                                </span>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="text-zinc-500 text-xs mt-3">
                                        ? = undrilled · ✕ = depleted
                                        <template v-if="state.player.owns_sabotage_scanner || toolbox.state.placementActive">
                                            · <span class="text-rose-400">☠</span> = rigged · <span class="text-amber-400">⚑</span> = your trap
                                        </template>
                                    </div>
                                    <div v-if="state.player.owns_sabotage_scanner" class="text-[10px] text-amber-400/80 mt-1 uppercase tracking-widest">Deep Scanner active — rigged cells highlighted</div>
                                </div>

                                <!-- Post -->
                                <div v-else-if="state.tile_detail?.kind === 'post'" class="text-left">
                                    <div class="text-zinc-500 text-xs uppercase mb-3 tracking-widest text-center">{{ state.tile_detail.post_type }} post · inventory</div>
                                    <div v-if="state.tile_detail.items.length === 0" class="text-zinc-500 text-sm italic text-center">No items for sale at this post type yet.</div>
                                    <div v-else class="space-y-2">
                                        <template v-for="(item, idx) in state.tile_detail.items" :key="item.key">
                                            <div
                                                v-if="item.category && item.category !== state.tile_detail.items[idx - 1]?.category"
                                                class="flex items-center gap-3 pt-3 first:pt-0"
                                            >
                                                <div class="h-px flex-1 bg-zinc-800"></div>
                                                <div class="text-amber-400 text-[10px] sm:text-xs uppercase tracking-widest font-bold">{{ item.category }}</div>
                                                <div class="h-px flex-1 bg-zinc-800"></div>
                                            </div>
                                            <div class="bg-zinc-900 border border-zinc-800 rounded p-3 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                <div class="min-w-0 flex-1">
                                                    <div class="text-zinc-100 text-sm font-bold break-words">
                                                        {{ item.name }}
                                                        <span v-if="item.owned_quantity > 0" class="ml-1 text-amber-400/80 text-[10px] uppercase tracking-widest">owned ×{{ item.owned_quantity }}</span>
                                                    </div>
                                                    <div v-if="item.description" class="text-zinc-500 text-xs mt-0.5 break-words">{{ item.description }}</div>
                                                    <div class="text-emerald-400 text-xs mt-1 break-words">
                                                        <span v-for="(effect, effectIdx) in formatEffects(item.effects)" :key="effectIdx" class="mr-2">{{ effect }}</span>
                                                    </div>
                                                </div>
                                                <div class="flex flex-row items-center justify-between gap-2 border-t border-zinc-800 pt-2 sm:flex-col sm:items-end sm:justify-start sm:border-t-0 sm:pt-0 sm:shrink-0 sm:max-w-[45%]">
                                                    <div class="text-amber-400 text-xs font-bold break-words sm:text-right">{{ formatPrice(item) }}</div>
                                                    <div class="flex flex-col items-end gap-1">
                                                        <button
                                                            type="button"
                                                            class="bg-amber-500 hover:bg-amber-400 text-zinc-950 text-xs font-bold uppercase tracking-wider px-4 py-2 rounded transition disabled:opacity-30 disabled:cursor-not-allowed"
                                                            :disabled="!item.can_purchase"
                                                            @click="buy(item)"
                                                        >Buy</button>
                                                        <div v-if="!item.can_afford" class="text-rose-400 text-[10px] uppercase tracking-widest">Can't afford</div>
                                                        <div v-else-if="item.block_reason" class="text-rose-400 text-[10px] uppercase tracking-widest">{{ item.block_reason }}</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>

                                <!-- Casino -->
                                <div v-else-if="state.tile_detail?.kind === 'casino'" class="rounded border border-amber-900/60 bg-amber-950/20 p-3 sm:p-4 text-center">
                                    <div class="text-amber-400 text-xs uppercase tracking-widest mb-2">Casino</div>
                                    <div class="text-xl sm:text-2xl font-bold text-amber-300 mb-2">
                                        {{ state.tile_detail.name }}
                                    </div>
                                    <div class="text-zinc-500 text-xs mb-3">
                                        <span v-if="state.tile_detail.has_active_session">
                                            You have an active session &mdash; resume play
                                        </span>
                                        <span v-else>
                                            Entry fee: <span class="text-amber-400 font-semibold">{{ state.tile_detail.entry_fee_barrels }} barrels</span>
                                        </span>
                                    </div>
                                    <Link
                                        :href="route('casino.show')"
                                        class="inline-block bg-amber-500 hover:bg-amber-400 text-zinc-950 text-sm font-bold uppercase tracking-wider px-4 py-2 rounded transition"
                                    >
                                        {{ state.tile_detail.has_active_session ? 'Resume' : 'Enter Saloon' }}
                                    </Link>
                                </div>

                                <!-- Own base -->
                                <div v-else-if="state.tile_detail?.kind === 'own_base'" class="rounded border border-zinc-800 bg-zinc-900 p-3 sm:p-4">
                                    <div class="text-zinc-500 text-xs uppercase mb-3 tracking-widest">Your base vault</div>
                                    <div class="grid grid-cols-3 gap-2 sm:gap-4 text-sm">
                                        <div class="min-w-0">
                                            <div class="text-zinc-500 text-[10px] sm:text-xs">Stored cash</div>
                                            <div class="text-amber-400 text-base sm:text-lg break-words">A{{ state.tile_detail.stored_cash.toFixed(2) }}</div>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="text-zinc-500 text-[10px] sm:text-xs">Oil barrels</div>
                                            <div class="text-zinc-100 text-base sm:text-lg break-words">{{ state.tile_detail.stored_oil_barrels }}</div>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="text-zinc-500 text-[10px] sm:text-xs">Intel</div>
                                            <div class="text-zinc-100 text-base sm:text-lg break-words">{{ state.tile_detail.stored_intel }}</div>
                                        </div>
                                    </div>
                                </div>

                                <!-- ENEMY BASE with spy/attack -->
                                <div v-else-if="state.tile_detail?.kind === 'enemy_base'" class="rounded border border-rose-900/60 bg-rose-950/20 p-3 sm:p-4 text-left">
                                    <div class="text-rose-400 text-xs uppercase tracking-widest mb-2">Enemy base</div>
                                    <div class="text-xl sm:text-2xl font-bold text-zinc-100 mb-1 break-words">
                                        {{ state.tile_detail.owner_username ?? 'Unknown' }}
                                        <span
                                            v-if="state.tile_detail.owner_mdn_tag"
                                            class="ml-2 font-mono text-sm text-amber-300"
                                            :title="state.tile_detail.owner_mdn_name ?? ''"
                                        >
                                            [{{ state.tile_detail.owner_mdn_tag }}]
                                        </span>
                                    </div>
                                    <div
                                        v-if="state.tile_detail.same_mdn_blocked"
                                        class="text-amber-400 text-sm mb-3 italic"
                                    >
                                        Fellow MDN member — cannot be spied on or attacked.
                                    </div>
                                    <div
                                        v-else-if="state.tile_detail.owner_immune"
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

                                    <div class="flex flex-col sm:flex-row gap-2 mt-4">
                                        <button
                                            type="button"
                                            class="flex-1 bg-violet-800 hover:bg-violet-700 border border-violet-600 text-zinc-100 text-sm font-bold uppercase tracking-wider px-4 py-3 rounded transition disabled:opacity-30 disabled:cursor-not-allowed"
                                            :disabled="state.tile_detail.owner_immune || state.tile_detail.same_mdn_blocked"
                                            @click="spy"
                                        >
                                            Spy ({{ state.tile_detail.spy_move_cost }} moves)
                                        </button>
                                        <button
                                            type="button"
                                            class="flex-1 bg-rose-800 hover:bg-rose-700 border border-rose-600 text-zinc-100 text-sm font-bold uppercase tracking-wider px-4 py-3 rounded transition disabled:opacity-30 disabled:cursor-not-allowed"
                                            :disabled="!canAttackNow || state.tile_detail.same_mdn_blocked"
                                            @click="attack"
                                        >
                                            Attack ({{ state.tile_detail.attack_move_cost }} moves)
                                        </button>
                                    </div>
                                </div>

                                <!-- Wasteland — with opportunistic tile combat -->
                                <div v-else-if="state.tile_detail?.kind === 'wasteland'" class="text-left">
                                    <div v-if="state.tile_detail.occupants.length === 0" class="text-zinc-600 text-sm italic text-center">
                                        Empty wasteland. Just you and the dust — keep walking.
                                    </div>
                                    <div v-else>
                                        <div class="text-rose-400 text-xs uppercase tracking-widest mb-3 text-center flex items-center justify-center gap-2">
                                            <span class="inline-block h-2 w-2 rounded-full bg-rose-400 animate-pulse"></span>
                                            People here
                                        </div>
                                        <div class="space-y-2 mb-3">
                                            <div
                                                v-for="occ in state.tile_detail.occupants"
                                                :key="occ.player_id"
                                                class="rounded border border-rose-900/60 bg-rose-950/20 p-3 flex items-start justify-between gap-2"
                                            >
                                                <div class="flex-1 min-w-0">
                                                    <div class="text-zinc-100 text-base font-bold break-words">
                                                        {{ occ.username }}
                                                        <span
                                                            v-if="occ.mdn_tag"
                                                            class="ml-2 font-mono text-xs text-amber-300"
                                                            :title="occ.mdn_name ?? ''"
                                                        >[{{ occ.mdn_tag }}]</span>
                                                        <span
                                                            v-if="occ.is_bot"
                                                            class="ml-2 text-[10px] uppercase tracking-widest text-zinc-500"
                                                        >bot</span>
                                                    </div>
                                                    <div
                                                        v-if="!occ.can_fight && occ.block_reason_label"
                                                        class="text-amber-400/80 text-xs mt-1 italic"
                                                    >
                                                        {{ occ.block_reason_label }}
                                                    </div>
                                                    <div v-else-if="occ.can_fight" class="text-zinc-500 text-xs mt-1">
                                                        Fight them for up to {{ (state.tile_detail.max_oil_loot_pct * 100).toFixed(0) }}% of their oil — or lose up to the same of yours.
                                                    </div>
                                                </div>
                                                <div class="shrink-0 flex flex-col items-end gap-1">
                                                    <button
                                                        type="button"
                                                        class="bg-rose-700 hover:bg-rose-600 border border-rose-500 text-zinc-100 text-xs font-bold uppercase tracking-wider px-3 py-2 rounded transition disabled:opacity-30 disabled:cursor-not-allowed"
                                                        :disabled="!occ.can_fight"
                                                        :title="occ.can_fight ? 'Initiate a duel' : occ.block_reason_label"
                                                        @click="openFightConfirm(occ)"
                                                    >
                                                        Fight
                                                    </button>
                                                    <div class="text-[10px] text-zinc-500 uppercase tracking-widest">
                                                        {{ state.tile_detail.move_cost }} moves
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="text-zinc-500 text-[11px] italic text-center">
                                            One fight per tile per {{ state.tile_detail.cooldown_hours }}h for either participant.
                                        </div>
                                    </div>
                                </div>

                                <!-- Landmark / ruin fallback -->
                                <div v-else class="text-zinc-600 text-sm italic">Nothing to do here but keep walking.</div>
                            </div>
                        </div>

                        <!-- E button (desktop cross layout) -->
                        <button
                            type="button"
                            class="hidden sm:w-20 md:w-28 shrink-0 bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 hover:border-amber-400 rounded sm:flex flex-col items-center justify-center gap-1 sm:gap-2 px-1 sm:px-2 py-3 sm:py-4 disabled:opacity-30 disabled:cursor-not-allowed transition"
                            :disabled="!neighborByDirection.e"
                            @click="travel('e')"
                        >
                            <span class="text-amber-400 text-xl sm:text-2xl">→</span>
                            <TileIcon v-if="neighborByDirection.e" :type="neighborByDirection.e.type" class="w-6 h-6 sm:w-8 sm:h-8" :class="tileColor(neighborByDirection.e.type)" />
                            <div class="text-[10px] sm:text-xs text-zinc-500 uppercase tracking-widest flex items-center gap-1">
                                <span>East</span>
                                <span v-if="activeTransportInfo.spaces > 1" class="text-amber-400 font-bold">×{{ activeTransportInfo.spaces }}</span>
                            </div>
                            <div class="hidden sm:block text-xs text-zinc-300 break-words text-center">
                                <template v-if="neighborByDirection.e">{{ neighborByDirection.e.type }}</template>
                                <template v-else-if="activeTransportInfo.spaces > 1">— past frontier —</template>
                                <template v-else>— edge —</template>
                            </div>
                        </button>
                    </div>

                    <!-- S button (desktop cross layout) -->
                    <button
                        type="button"
                        class="hidden w-full bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 hover:border-amber-400 rounded sm:flex items-center justify-center gap-2 sm:gap-3 py-2 sm:py-3 disabled:opacity-30 disabled:cursor-not-allowed transition mt-3"
                        :disabled="!neighborByDirection.s"
                        @click="travel('s')"
                    >
                        <span class="text-amber-400 text-xl sm:text-2xl">↓</span>
                        <TileIcon v-if="neighborByDirection.s" :type="neighborByDirection.s.type" class="w-6 h-6 sm:w-8 sm:h-8" :class="tileColor(neighborByDirection.s.type)" />
                        <div class="text-left min-w-0">
                            <div class="text-[10px] sm:text-xs text-zinc-500 uppercase tracking-widest flex items-center gap-1.5">
                                <span>South</span>
                                <span v-if="activeTransportInfo.spaces > 1" class="text-amber-400 font-bold">×{{ activeTransportInfo.spaces }}</span>
                                <span v-if="activeTransportInfo.fuel > 0" class="text-rose-400">· {{ activeTransportInfo.fuel }} fuel</span>
                            </div>
                            <div class="text-xs sm:text-sm text-zinc-300 break-words">
                                <template v-if="neighborByDirection.s">{{ neighborByDirection.s.type }}</template>
                                <template v-else-if="activeTransportInfo.spaces > 1">— past the frontier —</template>
                                <template v-else>— edge —</template>
                            </div>
                        </div>
                    </button>

                    <div class="mt-4 text-zinc-500 text-xs text-center">Tiles discovered: {{ state.discovered_count }}</div>
                </div>
            </div>
        </div>

        <TeleportModal
            v-if="showTeleportModal"
            :cost-barrels="teleportCost"
            :owns-teleporter="state.player.owns_teleporter ?? false"
            @close="showTeleportModal = false"
        />

        <!-- Tile combat confirmation modal -->
        <div
            v-if="fightConfirmTarget && wastelandDetailForModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4"
            @click.self="cancelFight"
        >
            <div class="bg-zinc-900 border-2 border-rose-500/50 rounded-lg p-5 sm:p-6 max-w-md w-full font-mono shadow-2xl shadow-rose-900/30">
                <div class="text-rose-400 text-xs uppercase tracking-widest mb-2">Wasteland duel</div>
                <div class="text-zinc-100 text-xl font-bold mb-3 break-words">
                    Fight {{ fightConfirmTarget.username }}?
                    <span
                        v-if="fightConfirmTarget.mdn_tag"
                        class="ml-2 font-mono text-sm text-amber-300"
                    >[{{ fightConfirmTarget.mdn_tag }}]</span>
                </div>
                <div class="text-zinc-300 text-sm mb-4 leading-relaxed">
                    <p class="mb-2">
                        Strength decides the winner. A weaker attacker who wins gets up to
                        <span class="text-amber-400 font-bold">{{ (wastelandDetailForModal.max_oil_loot_pct * 100).toFixed(0) }}%</span>
                        of the loser's oil; a bully gets almost nothing.
                    </p>
                    <p class="text-rose-300">
                        If you lose, they take the same share from YOUR stash.
                        Costs <span class="font-bold">{{ wastelandDetailForModal.move_cost }} moves</span> regardless.
                    </p>
                </div>
                <!-- Inline error: shown when a POST came back 422 and we
                     kept the modal open so the player can read why. -->
                <div
                    v-if="tileCombatError"
                    class="mb-3 rounded border border-rose-800 bg-rose-950/60 p-2 text-rose-300 text-xs"
                >
                    {{ tileCombatError }}
                </div>
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="flex-1 bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 text-zinc-300 text-sm font-bold uppercase tracking-wider px-4 py-2 rounded transition"
                        :disabled="fightInFlight"
                        @click="cancelFight"
                    >
                        Back off
                    </button>
                    <button
                        type="button"
                        class="flex-1 bg-rose-700 hover:bg-rose-600 border border-rose-500 text-zinc-100 text-sm font-bold uppercase tracking-wider px-4 py-2 rounded transition disabled:opacity-50 disabled:cursor-not-allowed"
                        :disabled="fightInFlight"
                        @click="confirmFight"
                    >
                        {{ fightInFlight ? 'Fighting…' : 'Throw down' }}
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
@keyframes drill-popup-float {
    0%   { opacity: 0;   transform: translate(-50%, 0);     }
    15%  { opacity: 1;   transform: translate(-50%, -6px);  }
    70%  { opacity: 1;   transform: translate(-50%, -26px); }
    100% { opacity: 0;   transform: translate(-50%, -40px); }
}
.drill-popup {
    animation: drill-popup-float 1300ms ease-out forwards;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.9);
}

/* Break popup shakes briefly in place below the cell, then fades. */
@keyframes drill-popup-break {
    0%   { opacity: 0;   transform: translate(-50%, 0)    scale(0.8); }
    10%  { opacity: 1;   transform: translate(-55%, 2px)  scale(1.1); }
    20%  { opacity: 1;   transform: translate(-45%, 4px)  scale(1.1); }
    30%  { opacity: 1;   transform: translate(-55%, 2px)  scale(1.0); }
    40%  { opacity: 1;   transform: translate(-45%, 4px)  scale(1.0); }
    80%  { opacity: 1;   transform: translate(-50%, 6px)  scale(1.0); }
    100% { opacity: 0;   transform: translate(-50%, 14px) scale(0.9); }
}
.drill-popup-break {
    animation: drill-popup-break 1600ms ease-out forwards;
    text-shadow: 0 0 6px rgba(244, 63, 94, 0.9), 0 1px 2px rgba(0, 0, 0, 0.9);
    letter-spacing: 0.08em;
}
</style>
